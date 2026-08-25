<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;

/**
 * Single source of truth for "what stock should the UI show" — always
 * InventoryItem -> WarehouseInventoryBalance for the given warehouse
 * (normally the store's primary warehouse), never variant.qty or a stale
 * legacy `stocks` row. The legacy `stocks` table is read only as a
 * compatibility fallback when no balance has ever been recorded yet (a
 * variant that has genuinely never had any inventory activity) — flagged
 * via `inventory_missing` so callers/UI can tell "nothing recorded" apart
 * from "a real balance that happens to be zero".
 *
 * Both Product (simple products) and ProductVariant expose the same shape
 * of relations (inventoryLink -> inventoryItem -> balances, and a legacy
 * `stocks` HasMany), so one snapshot method serves both.
 */
class ProductStockSnapshotService
{
    /** @return array{stock_on_hand: int, stock_reserved: int, stock_available: int, warehouse_id: ?string, inventory_item_id: ?string, inventory_missing: bool} */
    public function forProduct(Product $product, ?Warehouse $warehouse): array
    {
        return $this->snapshot($product, $warehouse);
    }

    /** @return array{stock_on_hand: int, stock_reserved: int, stock_available: int, warehouse_id: ?string, inventory_item_id: ?string, inventory_missing: bool} */
    public function forVariant(ProductVariant $variant, ?Warehouse $warehouse): array
    {
        return $this->snapshot($variant, $warehouse);
    }

    /** Computes and assigns the snapshot props directly onto the model (as dynamic attributes), for Inertia props. */
    public function applyToProduct(Product $product, ?Warehouse $warehouse): void
    {
        $this->apply($product, $this->forProduct($product, $warehouse));
    }

    public function applyToVariant(ProductVariant $variant, ?Warehouse $warehouse): void
    {
        $this->apply($variant, $this->forVariant($variant, $warehouse));
    }

    /**
     * @param  Product|ProductVariant  $model
     * @return array{stock_on_hand: int, stock_reserved: int, stock_available: int, warehouse_id: ?string, inventory_item_id: ?string, inventory_missing: bool}
     */
    private function snapshot(Product|ProductVariant $model, ?Warehouse $warehouse): array
    {
        $balance = $warehouse !== null
            ? $model->inventoryLink?->inventoryItem?->balances->firstWhere('warehouse_id', $warehouse->id)
            : null;

        // The Shopify external inventory_item_id (used to push stock levels
        // via InventoryLevel), not the internal InventoryItem primary key —
        // this is what ProductController's original applyVariantStockProps()
        // exposed and the UI/tests key off of.
        $shopifyInventoryItemId = $model->channelListings
            ->first(fn ($listing) => $listing->connection?->platform === 'shopify')
            ?->external_inventory_item_id;

        if ($balance !== null) {
            return [
                'stock_on_hand' => (int) $balance->on_hand,
                'stock_reserved' => (int) $balance->reserved,
                'stock_available' => (int) $balance->available(),
                'warehouse_id' => $warehouse?->id,
                'inventory_item_id' => $shopifyInventoryItemId,
                'inventory_missing' => false,
            ];
        }

        // Compatibility fallback only — no WarehouseInventoryBalance exists
        // yet for this (item, warehouse) pair. The legacy `stocks` row (if
        // any) is the best honest answer available; inventory_missing tells
        // the caller nothing has actually been recorded through the
        // inventory engine.
        $legacyQuantity = $warehouse !== null
            ? (int) ($model->stocks->firstWhere('warehouse_id', $warehouse->id)->quantity ?? 0)
            : 0;

        return [
            'stock_on_hand' => $legacyQuantity,
            'stock_reserved' => 0,
            'stock_available' => $legacyQuantity,
            'warehouse_id' => $warehouse?->id,
            'inventory_item_id' => $shopifyInventoryItemId,
            'inventory_missing' => true,
        ];
    }

    /** @param array{stock_on_hand: int, stock_reserved: int, stock_available: int, warehouse_id: ?string, inventory_item_id: ?string, inventory_missing: bool} $snapshot */
    private function apply(Product|ProductVariant $model, array $snapshot): void
    {
        foreach ($snapshot as $key => $value) {
            $model->{$key} = $value;
        }
    }

    /**
     * Multi-warehouse aggregate — for the Stock list/grid and the Adjust
     * Stock modal, where "current stock" means "summed across every
     * sellable warehouse", not one specific one. Same InventoryItem ->
     * WarehouseInventoryBalance source of truth as forProduct()/forVariant();
     * never the legacy `stocks` table except as the same honest
     * `inventory_missing` fallback. `$waitingByItemId` lets a caller listing
     * many rows batch the waiting-shortage query ONCE (via waitingDemandFor()
     * below) instead of once per row — pass [] to fall back to a live
     * per-call query when only resolving a single item.
     *
     * @param  array<int, string>  $warehouseIds
     * @param  array<string, int>  $waitingByItemId
     * @return array{on_hand: int, reserved: int, transfer_reserved: int, available: int, waiting_demand: int, inventory_item_id: ?string, inventory_missing: bool}
     */
    public function forProductAcrossWarehouses(Product $product, array $warehouseIds, array $waitingByItemId = []): array
    {
        return $this->aggregate($product, $warehouseIds, $waitingByItemId);
    }

    /** @see forProductAcrossWarehouses() */
    public function forVariantAcrossWarehouses(ProductVariant $variant, array $warehouseIds, array $waitingByItemId = []): array
    {
        return $this->aggregate($variant, $warehouseIds, $waitingByItemId);
    }

    /**
     * @param  array<int, string>  $warehouseIds
     * @param  array<string, int>  $waitingByItemId
     * @return array{on_hand: int, reserved: int, transfer_reserved: int, available: int, waiting_demand: int, inventory_item_id: ?string, inventory_missing: bool}
     */
    private function aggregate(Product|ProductVariant $model, array $warehouseIds, array $waitingByItemId): array
    {
        $item = $model->inventoryLink?->inventoryItem;

        if ($item === null || $warehouseIds === []) {
            // Compatibility fallback — no InventoryItem linked yet (never
            // adjusted through the engine). Honest legacy number, flagged.
            $legacyQty = (int) $model->stocks
                ->filter(fn ($s) => in_array($s->warehouse_id, $warehouseIds, true))
                ->sum('quantity');

            return [
                'on_hand' => $legacyQty, 'reserved' => 0, 'transfer_reserved' => 0, 'available' => $legacyQty,
                'waiting_demand' => 0, 'inventory_item_id' => null, 'inventory_missing' => true,
            ];
        }

        $balances = $item->balances->filter(fn ($b) => in_array($b->warehouse_id, $warehouseIds, true));
        $onHand = (int) $balances->sum('on_hand');
        $reserved = (int) $balances->sum('reserved');
        $transferReserved = (int) $balances->sum('transfer_reserved');
        $available = max(0, $onHand - $reserved - $transferReserved);
        $waiting = $waitingByItemId[$item->id] ?? $this->waitingDemandFor([$item->id], $warehouseIds)[$item->id] ?? 0;

        return [
            'on_hand' => $onHand, 'reserved' => $reserved, 'transfer_reserved' => $transferReserved, 'available' => $available,
            'waiting_demand' => $waiting, 'inventory_item_id' => $item->id, 'inventory_missing' => false,
        ];
    }

    /**
     * Batched open-shortage total per InventoryItem, across the given
     * warehouses — one query for an entire page of products/variants rather
     * than one per row. "Waiting demand" is the sum of still-open
     * InventoryReservation.shortage_quantity: units real orders need that
     * this item/warehouse combination cannot currently cover.
     *
     * @param  array<int, string>  $inventoryItemIds
     * @param  array<int, string>  $warehouseIds
     * @return array<string, int> inventory_item_id => total open shortage
     */
    public function waitingDemandFor(array $inventoryItemIds, array $warehouseIds): array
    {
        if ($inventoryItemIds === [] || $warehouseIds === []) {
            return [];
        }

        return InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
            ->whereIn('inventory_item_id', $inventoryItemIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('shortage_quantity', '>', 0)
            ->selectRaw('inventory_item_id, SUM(shortage_quantity) as total')
            ->groupBy('inventory_item_id')
            ->pluck('total', 'inventory_item_id'))
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
