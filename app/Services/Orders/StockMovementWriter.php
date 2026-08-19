<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\InventoryAdjustment;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Jobs\SyncInventoryToWebhooks;
use Illuminate\Database\Eloquent\Model;

/**
 * The single place stock quantities are mutated by the order lifecycle.
 *
 * Every movement writes two records, which answer different questions:
 *   - StockLedger        — the human-facing audit trail (what happened, why, who)
 *   - InventoryAdjustment — the outbound queue that pushes to the storefronts
 *
 * Only movements that change SELLABLE stock get an InventoryAdjustment. Writing
 * one for a damaged-warehouse restock would advertise written-off units as
 * available for sale.
 */
class StockMovementWriter
{
    /**
     * Apply a signed quantity change to one product in one warehouse.
     *
     * @param  int  $change  negative to deduct, positive to restock
     * @return ?StockLedger  null when the line carries no local product to move
     */
    public function move(
        Store $store,
        ?string $productId,
        ?string $variantId,
        Warehouse $warehouse,
        int $change,
        string $ledgerType,
        Model $source,
        string $reference,
        ?User $actor = null,
        ?string $notes = null,
    ): ?StockLedger {
        // Online lines that were never matched to a local product can still be
        // counted and inspected, but there is nothing to move.
        if ($productId === null) {
            return null;
        }

        $stock = Stock::firstOrCreate(
            [
                'product_id'   => $productId,
                'variant_id'   => $variantId,
                'warehouse_id' => $warehouse->id,
            ],
            ['quantity' => 0],
        );

        // Lock the row: two staff acting on the same product at once would
        // otherwise both read the same "before" and lose one of the movements.
        $stock = Stock::whereKey($stock->id)->lockForUpdate()->first();

        $before = (int) $stock->quantity;
        $after  = $before + $change;

        $stock->update(['quantity' => $after]);

        $ledger = StockLedger::create([
            'store_id'         => $store->id,
            'product_id'       => $productId,
            'type'             => $ledgerType,
            'source_type'      => $source::class,
            'source_id'        => $source->getKey(),
            'quantity_change'  => $change,
            'stock_before'     => $before,
            'stock_after'      => $after,
            'reference_number' => $reference,
            'notes'            => $notes,
            'user_id'          => $actor?->id,
        ]);

        if ($warehouse->isSellable()) {
            $this->queueStorefrontSync($store, $productId, $source, $change, $before, $after, $ledgerType, $reference);
        }

        return $ledger;
    }

    /**
     * Tell the connected storefronts the sellable quantity moved. The job
     * recomputes the true sellable total itself; these columns are the audit of
     * what triggered it.
     */
    private function queueStorefrontSync(
        Store $store,
        string $productId,
        Model $source,
        int $change,
        int $before,
        int $after,
        string $ledgerType,
        string $reference,
    ): void {
        $adjustment = InventoryAdjustment::create([
            'store_id'        => $store->id,
            'product_id'      => $productId,
            'source'          => $ledgerType === 'return' ? 'return_restock' : 'online_order',
            'adjustable_type' => $source::class,
            'adjustable_id'   => $source->getKey(),
            'quantity_change' => $change,
            'quantity_before' => $before,
            'quantity_after'  => $after,
            'sync_status'     => 'pending',
            'notes'           => $reference,
        ]);

        SyncInventoryToWebhooks::dispatch($store, $adjustment);
    }
}
