<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OrderLineItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverse logistics: receiving returned goods and routing each line to active or
 * damaged stock.
 *
 * Stock moves here and nowhere else in the return flow. Flagging an order as
 * returned deliberately moves nothing — the goods are unverified until an
 * inspector physically handles them.
 */
class ReturnInspectionService
{
    public function __construct(
        private readonly StockMovementWriter $stock,
    ) {}

    /**
     * Open a return for an order, snapshotting every line at full quantity for
     * the inspector to adjust. Idempotent: an order with an open return gets
     * that one back rather than a second.
     */
    public function open(
        Order|PosOrder $order,
        string $reason,
        ?User $actor = null,
        ?string $notes = null,
    ): OrderReturn {
        $existing = OrderReturn::query()
            ->where('returnable_type', $order::class)
            ->where('returnable_id', $order->getKey())
            ->open()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $reason, $actor, $notes) {
            $store = $order->store;

            $return = OrderReturn::create([
                'store_id'        => $store->id,
                'returnable_type' => $order::class,
                'returnable_id'   => $order->getKey(),
                'reference'       => $this->generateReference($store),
                'status'          => OrderReturn::STATUS_AWAITING_INSPECTION,
                'reason'          => in_array($reason, OrderReturn::reasons(), true) ? $reason : OrderReturn::REASON_OTHER,
                'notes'           => $notes ?? (in_array($reason, OrderReturn::reasons(), true) ? null : $reason),
                'flagged_by'      => $actor?->id,
                'flagged_at'      => now(),
            ]);

            foreach (OrderLineItems::for($order) as $line) {
                OrderReturnItem::create([
                    'order_return_id'   => $return->id,
                    'product_id'        => $line['product_id'],
                    'variant_id'        => $line['variant_id'],
                    'product_name'      => $line['name'],
                    'product_sku'       => $line['sku'],
                    'quantity_ordered'  => $line['quantity'],
                    'quantity_returned' => $line['quantity'],
                    'refund_amount'     => $line['line_total'],
                ]);
            }

            return $return->load('items');
        });
    }

    /** Move a return from the queue onto an inspector's bench. */
    public function startInspection(OrderReturn $return, User $actor): OrderReturn
    {
        if ($return->status === OrderReturn::STATUS_AWAITING_INSPECTION) {
            $return->update([
                'status'       => OrderReturn::STATUS_INSPECTING,
                'inspected_by' => $actor->id,
                'inspected_at' => now(),
            ]);

            $this->advanceOrder($return, FulfillmentStatus::UnderInspection, $actor);
        }

        return $return->refresh();
    }

    /**
     * Record the inspector's verdict on one or more lines and move the stock
     * that follows from it.
     *
     * @param  array<int, array{item_id: string, condition: string, quantity?: int, notes?: ?string}>  $lines
     *
     * @throws ValidationException on an unknown condition or a quantity above what went out
     */
    public function disposition(OrderReturn $return, array $lines, User $actor): OrderReturn
    {
        if ($return->isClosed()) {
            throw ValidationException::withMessages([
                'return' => 'This return has already been closed.',
            ]);
        }

        $this->startInspection($return, $actor);

        DB::transaction(function () use ($return, $lines, $actor) {
            $store = $return->store;

            foreach ($lines as $line) {
                $item = $return->items()->whereKey($line['item_id'])->first();

                if ($item === null) {
                    continue;
                }

                // Already restocked — a resubmitted form must not move stock twice.
                if ($item->hasStockMovement()) {
                    continue;
                }

                $condition = $line['condition'];

                if (! in_array($condition, OrderReturnItem::conditions(), true)) {
                    throw ValidationException::withMessages([
                        'condition' => "Unknown condition [{$condition}].",
                    ]);
                }

                $quantity = (int) ($line['quantity'] ?? $item->quantity_returned);

                if ($quantity < 0 || $quantity > $item->quantity_ordered) {
                    throw ValidationException::withMessages([
                        'quantity' => "Cannot receive {$quantity} of {$item->product_name}; only {$item->quantity_ordered} went out.",
                    ]);
                }

                $warehouse = $this->destinationFor($store, $condition);

                // Missing goods never arrived, so there is nothing to move. The
                // OrderReturnItem row is itself the record of the write-off.
                $ledger = $warehouse === null ? null : $this->stock->move(
                    store:      $store,
                    productId:  $item->product_id,
                    variantId:  $item->variant_id,
                    warehouse:  $warehouse,
                    change:     $quantity,
                    ledgerType: $condition === OrderReturnItem::CONDITION_RESELLABLE ? 'return' : 'damage',
                    source:     $return,
                    reference:  $return->reference,
                    actor:      $actor,
                    notes:      "Return {$return->reference} — {$condition}",
                );

                $item->update([
                    'condition'                => $condition,
                    'quantity_returned'        => $quantity,
                    'destination_warehouse_id' => $warehouse?->id,
                    'stock_ledger_id'          => $ledger?->id,
                    'inspection_notes'         => $line['notes'] ?? null,
                    'refund_amount'            => round($item->quantity_ordered > 0
                        ? (float) $item->refund_amount / $item->quantity_ordered * $quantity
                        : 0.0, 2),
                    'dispositioned_at'         => now(),
                ]);
            }
        });

        return $return->refresh()->load('items');
    }

    /**
     * Close a fully inspected return and move the order to its terminal state.
     *
     * The "every line dispositioned" rule lives here rather than in
     * FulfillmentStatus::transitions() because it depends on other rows — the
     * enum only knows which edges exist, so the UI can render the button
     * disabled instead of hiding it.
     *
     * @throws ValidationException while any line is still un-inspected
     */
    public function close(OrderReturn $return, User $actor): OrderReturn
    {
        if ($return->isClosed()) {
            return $return;
        }

        if (! $return->isFullyDispositioned()) {
            $pending = $return->items()->whereNull('condition')->count();

            throw ValidationException::withMessages([
                'return' => "{$pending} line(s) still need a condition before this return can be closed.",
            ]);
        }

        $return->update([
            'status'    => OrderReturn::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->advanceOrder($return, FulfillmentStatus::ReturnCompleted, $actor);

        return $return->refresh();
    }

    /** @return array{restocked: int, damaged: int, missing: int} */
    public function outcomeSummary(OrderReturn $return): array
    {
        $items = $return->relationLoaded('items') ? $return->items : $return->items()->get();

        return [
            'restocked' => (int) $items->where('condition', OrderReturnItem::CONDITION_RESELLABLE)->sum('quantity_returned'),
            'damaged'   => (int) $items->where('condition', OrderReturnItem::CONDITION_DAMAGED)->sum('quantity_returned'),
            'missing'   => (int) $items->where('condition', OrderReturnItem::CONDITION_MISSING)->sum('quantity_returned'),
        ];
    }

    // -------------------------------------------------------------------------

    /** Null means "no stock movement" — the units never came back. */
    private function destinationFor(Store $store, string $condition): ?Warehouse
    {
        return match ($condition) {
            OrderReturnItem::CONDITION_RESELLABLE => $store->getPrimaryWarehouse(),
            OrderReturnItem::CONDITION_DAMAGED    => $store->getDamagedWarehouse(),
            default                               => null,
        };
    }

    /**
     * Resolved lazily rather than injected: OrderWorkflowService depends on this
     * service to open a return, so a constructor dependency the other way would
     * be a container cycle.
     */
    private function advanceOrder(OrderReturn $return, FulfillmentStatus $target, User $actor): void
    {
        $order = $return->returnable;

        if ($order === null) {
            return;
        }

        $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        // Tolerate a return whose order was already advanced by hand.
        if (! $current->canTransitionTo($target)) {
            return;
        }

        app(OrderWorkflowService::class)->transition($order, $target, $actor);
    }

    /** RET-YYYYMMDD-0001, sequence restarting daily per store. */
    private function generateReference(Store $store): string
    {
        $date = now()->format('Ymd');

        return DB::transaction(function () use ($store, $date) {
            $todayCount = OrderReturn::query()
                ->where('store_id', $store->id)
                ->where('reference', 'like', "RET-{$date}-%")
                ->lockForUpdate()
                ->count();

            return sprintf('RET-%s-%04d', $date, $todayCount + 1);
        });
    }
}
