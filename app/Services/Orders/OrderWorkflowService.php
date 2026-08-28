<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\AgentActivityEvent;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\User;
use App\Services\Activity\AgentActivityRecorder;
use App\Services\Inventory\WarehouseAllocationService;
use App\Support\OrderLineItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every fulfillment status change goes through here — the board, the WhatsApp
 * confirmation webhook, and the returns flow all call transition().
 *
 * The enum owns which edges are legal; this owns what happens when one is taken:
 * the stock side-effects, the legacy `status` projection, and the audit entry,
 * all inside one transaction so a half-applied move is impossible.
 */
class OrderWorkflowService
{
    public function __construct(
        private readonly StockMovementWriter $stock,
        private readonly ReturnInspectionService $returns,
        private readonly WarehouseAllocationService $allocations,
        private readonly AgentActivityRecorder $activity,
    ) {}

    /**
     * @throws ValidationException when the edge is illegal or a reason is missing
     */
    public function transition(
        Order|PosOrder $order,
        FulfillmentStatus $target,
        ?User $actor = null,
        ?string $reason = null,
    ): Order|PosOrder {
        return DB::transaction(function () use ($order, $target, $actor, $reason) {
            // Re-read under a row lock. Without it two staff clicking "Confirm"
            // at the same moment both pass the check below and stock is
            // deducted twice.
            $order = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

            if (! $current->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot move a {$current->label()} order to {$target->label()}.",
                ]);
            }

            if ($target->requiresReason() && blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => "A reason is required to move an order to {$target->label()}.",
                ]);
            }

            $finalTarget = $this->applyStockEffects($order, $current, $target, $actor, $reason) ?? $target;

            $order->fill([
                'fulfillment_status'     => $finalTarget,
                'fulfillment_updated_at' => now(),
            ]);
            $this->syncLegacyStatus($order, $finalTarget);
            $order->save();

            if ($finalTarget === FulfillmentStatus::Returned) {
                $this->returns->open($order, (string) $reason, $actor);
            }

            activity('order')
                ->performedOn($order)
                ->causedBy($actor)
                ->event('fulfillment_transition')
                ->withProperties([
                    'from'      => $current->value,
                    'requested' => $target->value,
                    'to'        => $finalTarget->value,
                    'reason'    => $reason,
                ])
                ->log("Order moved to {$finalTarget->label()}");

            // Agent activity ledger — confirmation/fulfillment only (delivery
            // outcomes are recorded by DispatchService, which has the
            // OrderShipment context this method doesn't). Never fired when
            // there is no human actor (e.g. the WhatsApp reply path), which
            // is what keeps this "agent activity" rather than "every status
            // change." Purely additive: never throws, never changes the
            // transition's outcome.
            if ($actor !== null) {
                $this->recordActivity($order, $current, $target, $finalTarget, $actor, $reason);
            }

            return $order->refresh();
        });
    }

    private function recordActivity(
        Order|PosOrder $order,
        FulfillmentStatus $current,
        FulfillmentStatus $target,
        FulfillmentStatus $finalTarget,
        User $actor,
        ?string $reason,
    ): void {
        $store = $order->store;

        if ($store === null) {
            return;
        }

        [$eventType, $sourceModule] = match (true) {
            $current === FulfillmentStatus::Pending && $target === FulfillmentStatus::Confirmed
                => [AgentActivityEvent::CONFIRMATION_CONFIRMED, 'confirmation'],
            $current === FulfillmentStatus::Pending && $target === FulfillmentStatus::Cancelled
                => [AgentActivityEvent::CONFIRMATION_CANCELLED, 'confirmation'],
            $current === FulfillmentStatus::Picking && $finalTarget === FulfillmentStatus::Packing
                => [AgentActivityEvent::FULFILLMENT_PICKED, 'fulfillment'],
            in_array($current, [FulfillmentStatus::Packing, FulfillmentStatus::InProgress], true) && $finalTarget === FulfillmentStatus::ReadyForDelivery
                => [AgentActivityEvent::FULFILLMENT_PACKED, 'fulfillment'],
            default => [null, null],
        };

        if ($eventType === null) {
            return;
        }

        $metadata = ['from' => $current->value, 'to' => $finalTarget->value];

        if (in_array($eventType, [AgentActivityEvent::FULFILLMENT_PICKED, AgentActivityEvent::FULFILLMENT_PACKED], true)) {
            $metadata['units'] = collect(OrderLineItems::for($order))->sum('quantity');
        }

        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }

        $this->activity->record($actor, $store, $eventType, $sourceModule, [
            'subject' => $order,
            'order_id' => $order->getKey(),
            'metadata' => $metadata,
        ]);
    }

    /** Legal moves out of the order's current state, for building UI actions. */
    public function availableTransitions(Order|PosOrder $order): array
    {
        return ($order->fulfillment_status ?? FulfillmentStatus::Pending)->transitions();
    }

    // -------------------------------------------------------------------------
    // Stock
    // -------------------------------------------------------------------------

    /**
     * Commit stock when an order is first confirmed, release it if the order is
     * cancelled before dispatch. Nothing else on the forward path moves stock,
     * and — critically — flagging a return moves none either: the goods are
     * unverified until an inspector handles them, and restocking on the flag is
     * how phantom inventory gets created.
     */
    private function applyStockEffects(
        Order|PosOrder $order,
        FulfillmentStatus $current,
        FulfillmentStatus $target,
        ?User $actor,
        ?string $reason,
    ): ?FulfillmentStatus {
        // An online order line the platform clearly identified as a real
        // product/variant/sku, but that never resolved to a local product,
        // must never silently skip stock movement — block confirmation
        // instead of quietly allocating only the lines that happened to
        // match. A line the platform sent with no identifier at all (a
        // genuine custom/service line) is not stock-required and never blocks.
        if ($order instanceof Order && $current === FulfillmentStatus::Pending && $target === FulfillmentStatus::Confirmed) {
            $this->assertNoUnmappedStockedLines($order);
        }

        // V2 inventory semantics, shared by online orders AND POS delivery
        // orders alike:
        //   confirm (online) / checkout (POS) => reserve available stock
        //   ready_for_delivery => consume the reservation (goods leave the warehouse)
        //   cancellation before dispatch => release; cancellation after packing => restock
        // A POS order never passes through Pending -> Confirmed here (it is
        // born past that point — see FulfillmentType::initialFulfillmentStatus()
        // and OrderProcessingService::commitInventoryViaEngine(), which does the
        // equivalent allocate()/consume() at checkout time instead) — so that
        // branch below is effectively online-order-only, while ready_for_delivery
        // and cancellation are genuinely shared by both order types.
        if ($order instanceof Order || $order instanceof PosOrder) {
            $order->loadMissing('store.organization');

            // Organization-backed stores use the V2 inventory engine. A small
            // legacy fallback remains for pre-organization rows and old tests;
            // those rows keep the original Stock/StockLedger behavior until
            // they are migrated by the organization backfill.
            if ($order->store?->organization !== null) {
                if ($order instanceof Order && $current === FulfillmentStatus::Pending && $target === FulfillmentStatus::Confirmed) {
                    $allocation = $this->allocations->allocate($order, $order->shippingCity, $actor);

                    return $this->allocations->statusForAllocation($allocation);
                }

                if ($target === FulfillmentStatus::ReadyForDelivery) {
                    $this->allocations->consume($order, $actor);
                    return null;
                }

                if ($target === FulfillmentStatus::Cancelled) {
                    if ($current === FulfillmentStatus::ReadyForDelivery) {
                        $this->allocations->restoreConsumed($order, $actor);
                    } else {
                        $this->allocations->release($order, $actor);
                    }
                    return null;
                }

                return null;
            }
        }

        $wasCommitted = $this->stockIsCommitted($order, $current);
        $isCommitted  = $this->stockIsCommitted($order, $target);

        if ($wasCommitted === $isCommitted) {
            return null;
        }

        $store     = $order->store;
        $warehouse = $store->getPrimaryWarehouse();
        $reference = $this->reference($order);
        $direction = $isCommitted ? -1 : 1;

        foreach (OrderLineItems::for($order) as $line) {
            $this->stock->move(
                store:      $store,
                productId:  $line['product_id'],
                variantId:  $line['variant_id'],
                warehouse:  $warehouse,
                change:     $direction * $line['quantity'],
                ledgerType: $isCommitted ? 'sale' : 'adjustment',
                source:     $order,
                reference:  $reference,
                actor:      $actor,
                notes:      $isCommitted
                    ? "POS stock committed ({$reference})"
                    : "POS stock released ({$reference})" . ($reason ? ": {$reason}" : ''),
            );
        }

        return null;
    }

    private function assertNoUnmappedStockedLines(Order $order): void
    {
        $hasUnmapped = collect(OrderLineItems::for($order))->contains(fn (array $line) => $line['unmapped']);

        if ($hasUnmapped) {
            throw ValidationException::withMessages([
                'items' => 'Some lines are not linked to local inventory.',
            ]);
        }
    }

    /**
     * Whether the order's units are currently deducted from sellable stock.
     *
     * A POS sale deducts at checkout for both instant and delivery fulfillment,
     * so a POS order's stock is committed from the moment it exists — the
     * Pending → Confirmed step is a no-op for it, while a cancellation still
     * has to give the units back.
     */
    private function stockIsCommitted(Order|PosOrder $order, FulfillmentStatus $status): bool
    {
        if ($status === FulfillmentStatus::Cancelled) {
            return false;
        }

        if ($order instanceof PosOrder) {
            return true;
        }

        return ! in_array($status, [
            FulfillmentStatus::Pending,
            FulfillmentStatus::WaitingForStock,
            FulfillmentStatus::ReadyForPicking,
            FulfillmentStatus::Picking,
            FulfillmentStatus::Packing,
        ], true);
    }

    private function reference(Order|PosOrder $order): string
    {
        return $order instanceof PosOrder
            ? (string) $order->receipt_number
            : (string) $order->order_number;
    }

    // -------------------------------------------------------------------------
    // Legacy status projection
    // -------------------------------------------------------------------------

    /**
     * `status` is the commercial state mirrored to the platform and driven by the
     * customer; `fulfillment_status` is the internal operational state driven by
     * staff. Existing code — Order::scopePending(), scopeNeedsWhatsappConfirmation(),
     * the WhatsApp pipeline — reads the former, so keep it a projection of the
     * latter rather than letting the two drift.
     */
    private function syncLegacyStatus(Order|PosOrder $order, FulfillmentStatus $target): void
    {
        if ($order instanceof PosOrder) {
            // Only the cancellation is mirrored. A POS order's `status` also
            // encodes the delivery lane ('pending_delivery'), which
            // OrderPresenter derives the board's source tab from — rewriting it
            // on the forward path would silently move orders between tabs.
            if ($target === FulfillmentStatus::Cancelled) {
                $order->status = 'cancelled';
            }

            return;
        }

        $order->status = match ($target) {
            FulfillmentStatus::Pending   => OrderStatus::Pending,
            FulfillmentStatus::Cancelled => OrderStatus::Cancelled,
            FulfillmentStatus::Completed => OrderStatus::Completed,
            // The sale happened; a return is tracked separately and must not
            // reopen the order commercially.
            FulfillmentStatus::Returned,
            FulfillmentStatus::UnderInspection,
            FulfillmentStatus::ReturnCompleted => OrderStatus::Completed,
            default => OrderStatus::Confirmed,
        };
    }
}
