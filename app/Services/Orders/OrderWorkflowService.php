<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\InventoryAllocation;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\User;
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

            return $order->refresh();
        });
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
        // V2 inventory semantics for online orders:
        //   confirm => reserve available stock
        //   ready_for_delivery => consume the reservation (goods leave the warehouse)
        //   cancellation before dispatch => release; cancellation after packing => restock
        // POS checkout still uses the legacy stock writer for now; the Stock compatibility
        // bridge mirrors those writes into the organization-level Inventory Engine.
        if ($order instanceof Order) {
            $order->loadMissing('store.organization');

            // Organization-backed stores use the V2 inventory engine. A small
            // legacy fallback remains for pre-organization rows and old tests;
            // those rows keep the original Stock/StockLedger behavior until
            // they are migrated by the organization backfill.
            if ($order->store?->organization !== null) {
                if ($current === FulfillmentStatus::Pending && $target === FulfillmentStatus::Confirmed) {
                    $allocation = $this->allocations->allocate($order, $order->shippingCity, $actor);

                    return $this->statusAfterAllocation($allocation);
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

    private function statusAfterAllocation(InventoryAllocation $allocation): FulfillmentStatus
    {
        return match ($allocation->status) {
            InventoryAllocation::STATUS_RESERVED => FulfillmentStatus::ReadyForPicking,
            InventoryAllocation::STATUS_WAITING_TRANSFER,
            InventoryAllocation::STATUS_INSUFFICIENT => FulfillmentStatus::WaitingForStock,
            default => FulfillmentStatus::ReadyForPicking,
        };
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
