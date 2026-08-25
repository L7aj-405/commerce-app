<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\InventoryItem;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryReservation;
use App\Models\InventoryTransferItem;
use App\Models\OrderReturnItem;
use App\Models\PosOrderItem;
use App\Models\Product;
use App\Models\ProductInventoryLink;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockTransferItem;
use App\Models\VariantInventoryLink;
use App\Models\WarehouseInventoryBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only safety check for the product cleanup/resync-reset actions.
 *
 * For each product it answers: can it be archived, can its channel mappings
 * be unlinked, and — the strict one — can it be permanently purged. Purge is
 * blocked by ANY trace of operational history (orders, returns, transfers,
 * inventory ledger/reservations, non-zero stock) so a bulk purge can never
 * silently destroy something the rest of the app still points to.
 */
class ProductCleanupSafetyService
{
    /** Purge is permanently blocked (append-only ledger, real order/return history) — the only safe next step is to hide the product. */
    public const ACTION_ARCHIVE = 'archive';

    /** Purge is blocked only by an external mapping — resetting it removes the blocker without losing anything. */
    public const ACTION_RESET_SYNC = 'reset_sync';

    /** Purge is blocked only by a non-zero quantity the user can still change themselves. */
    public const ACTION_ADJUST_STOCK = 'adjust_stock';

    private const ACTION_LABELS = [
        self::ACTION_ARCHIVE => 'Archive product',
        self::ACTION_RESET_SYNC => 'Reset sync mapping',
        self::ACTION_ADJUST_STOCK => 'Adjust stock to zero, then archive; purge will still be blocked if ledger exists',
    ];

    /**
     * @return array{
     *     product_id:string,name:string,sku:?string,variant_count:int,listing_count:int,order_reference_count:int,
     *     can_archive:bool,can_unlink:bool,can_purge:bool,blockers:array<int,string>,warnings:array<int,string>,
     *     recommended_action:?string,recommended_action_label:?string,recommended_connection_id:?string,
     * }
     */
    public function check(Product $product): array
    {
        $product->loadMissing(['variants', 'channelListings', 'variants.channelListings']);

        $variantIds = $product->variants->pluck('id')->all();
        $blockers = [];
        $warnings = [];

        // Any of these are real, immutable operational history — the ONLY
        // safe recommendation once one of them exists is to archive; purge
        // will never become available for this product (ledger/order rows
        // are never deleted just to unblock a purge).
        $hasHistory = false;

        $posCount = $this->posOrderLineCount($product, $variantIds);
        if ($posCount > 0) {
            $blockers[] = "Cannot purge: product has {$posCount} POS order line(s).";
            $hasHistory = true;
        }

        $onlineCount = $this->onlineOrderLineCount($product, $variantIds);
        if ($onlineCount > 0) {
            $blockers[] = "Cannot purge: product has {$onlineCount} online order line(s).";
            $hasHistory = true;
        }

        $returnCount = $this->returnItemCount($product, $variantIds);
        if ($returnCount > 0) {
            $blockers[] = "Cannot purge: product has {$returnCount} return line item(s).";
            $hasHistory = true;
        }

        $transferCount = $this->stockTransferItemCount($product, $variantIds);
        if ($transferCount > 0) {
            $blockers[] = "Cannot purge: product has {$transferCount} stock transfer line item(s).";
            $hasHistory = true;
        }

        $invoiceCount = $this->invoiceLineCount($product, $variantIds);
        if ($invoiceCount > 0) {
            $blockers[] = "Cannot purge: product has {$invoiceCount} invoice line item(s).";
            $hasHistory = true;
        }

        $ledgerCount = $this->legacyStockLedgerCount($product, $variantIds);
        if ($ledgerCount > 0) {
            $blockers[] = "Cannot purge: product has {$ledgerCount} stock ledger entr" . ($ledgerCount === 1 ? 'y' : 'ies') . '.';
            $hasHistory = true;
        }

        $legacyStockNonZero = $this->legacyStockNonZeroCount($product);
        if ($legacyStockNonZero > 0) {
            $blockers[] = "Cannot purge: legacy stock quantity is not zero in {$legacyStockNonZero} warehouse(s).";
        }

        $inventoryItemIds = $this->resolveInventoryItemIds($product, $variantIds);
        $nonZeroBalances = 0;

        if ($inventoryItemIds->isNotEmpty()) {
            $engineLedgerCount = InventoryItem::withoutOrganizationTenancy(
                fn () => InventoryLedgerEntry::query()->whereIn('inventory_item_id', $inventoryItemIds)->count()
            );
            if ($engineLedgerCount > 0) {
                $blockers[] = "Cannot purge: inventory ledger has {$engineLedgerCount} entr" . ($engineLedgerCount === 1 ? 'y' : 'ies') . '.';
                $hasHistory = true;
            }

            $reservationCount = InventoryItem::withoutOrganizationTenancy(
                fn () => InventoryReservation::query()->whereIn('inventory_item_id', $inventoryItemIds)->count()
            );
            if ($reservationCount > 0) {
                $blockers[] = "Cannot purge: {$reservationCount} inventory reservation(s) exist.";
                $hasHistory = true;
            }

            $transferItemCount = InventoryItem::withoutOrganizationTenancy(
                fn () => InventoryTransferItem::query()->whereIn('inventory_item_id', $inventoryItemIds)->count()
            );
            if ($transferItemCount > 0) {
                $blockers[] = "Cannot purge: {$transferItemCount} inventory transfer line item(s) exist.";
                $hasHistory = true;
            }

            $nonZeroBalances = InventoryItem::withoutOrganizationTenancy(
                fn () => WarehouseInventoryBalance::query()
                    ->whereIn('inventory_item_id', $inventoryItemIds)
                    ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0)->orWhere('transfer_reserved', '!=', 0))
                    ->count()
            );
            if ($nonZeroBalances > 0) {
                $blockers[] = "Cannot purge: warehouse balance is not zero in {$nonZeroBalances} warehouse(s).";
            }
        }

        $channelListingCount = $product->channelListings->count();
        $variantListingCount = $product->variants->sum(fn ($v) => $v->channelListings->count());
        $hasChannelMapping = $channelListingCount > 0 || $variantListingCount > 0;

        if ($hasChannelMapping) {
            $warnings[] = 'Product has active external channel listing(s) — unlinking removes the mapping; future sync may recreate it or create a new product.';
        }

        $hasNonZeroStock = $legacyStockNonZero > 0 || $nonZeroBalances > 0;
        $canPurge = $blockers === [];

        [$recommendedAction, $recommendedConnectionId] = $canPurge
            ? [null, null]
            : $this->recommendAction($product, $hasHistory, $hasChannelMapping, $hasNonZeroStock);

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'variant_count' => count($variantIds),
            'listing_count' => $channelListingCount + $variantListingCount,
            'order_reference_count' => $posCount + $onlineCount,
            'can_archive' => true,
            'can_unlink' => true,
            'can_purge' => $canPurge,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'recommended_action' => $recommendedAction,
            'recommended_action_label' => $recommendedAction !== null ? self::ACTION_LABELS[$recommendedAction] : null,
            'recommended_connection_id' => $recommendedConnectionId,
        ];
    }

    /** @return array{0:?string,1:?string} [action, connection_id] */
    private function recommendAction(Product $product, bool $hasHistory, bool $hasChannelMapping, bool $hasNonZeroStock): array
    {
        if ($hasHistory) {
            return [self::ACTION_ARCHIVE, null];
        }

        if ($hasChannelMapping) {
            $connectionId = $product->channelListings->first()?->platform_connection_id
                ?? $product->variants->flatMap(fn ($v) => $v->channelListings)->first()?->platform_connection_id;

            return [self::ACTION_RESET_SYNC, $connectionId];
        }

        if ($hasNonZeroStock) {
            return [self::ACTION_ADJUST_STOCK, null];
        }

        // Blocked for some other reason (e.g. an orphaned reservation/ledger
        // row with no other signal) — archiving is always the safe fallback.
        return [self::ACTION_ARCHIVE, null];
    }

    /** @return Collection<int, array<string,mixed>> */
    public function checkMany(Collection $products): Collection
    {
        return $products->map(fn (Product $product) => $this->check($product))->values();
    }

    /** @param array<int,string> $variantIds */
    private function posOrderLineCount(Product $product, array $variantIds): int
    {
        return PosOrderItem::query()
            ->where(function ($q) use ($product, $variantIds): void {
                $q->where('product_id', $product->id);
                if ($variantIds !== []) {
                    $q->orWhereIn('variant_id', $variantIds);
                }
            })
            ->count();
    }

    /**
     * Online order lines live in a JSON blob (orders.items), keyed by whatever
     * the connector sent — a local id, a platform external id, or a SKU. A
     * substring LIKE search against every identifier this product could be
     * known by is the cheapest way to be safe here: a false positive only
     * makes purge more conservative, never less.
     *
     * @param array<int,string> $variantIds
     */
    private function onlineOrderLineCount(Product $product, array $variantIds): int
    {
        $identifiers = $this->productSearchIdentifiers($product, $variantIds);

        if ($identifiers->isEmpty() || $product->store_id === null) {
            return 0;
        }

        return DB::table('orders')
            ->where('store_id', $product->store_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($identifiers): void {
                foreach ($identifiers as $identifier) {
                    $q->orWhere('items', 'like', '%"' . addcslashes((string) $identifier, '%_\\') . '"%');
                }
            })
            ->count();
    }

    /** @param array<int,string> $variantIds @return Collection<int,string> */
    private function productSearchIdentifiers(Product $product, array $variantIds): Collection
    {
        $identifiers = collect([$product->id, $product->sku, $product->external_id])
            ->merge($variantIds)
            ->merge($product->variants->pluck('sku'))
            ->merge($product->channelListings->pluck('external_product_id'))
            ->merge(
                $product->variants->flatMap(fn ($v) => $v->channelListings->pluck('external_variant_id'))
            )
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->unique()
            ->values();

        return $identifiers;
    }

    /** @param array<int,string> $variantIds */
    private function returnItemCount(Product $product, array $variantIds): int
    {
        return OrderReturnItem::query()
            ->where(function ($q) use ($product, $variantIds): void {
                $q->where('product_id', $product->id);
                if ($variantIds !== []) {
                    $q->orWhereIn('variant_id', $variantIds);
                }
            })
            ->count();
    }

    /** @param array<int,string> $variantIds */
    private function stockTransferItemCount(Product $product, array $variantIds): int
    {
        return StockTransferItem::query()
            ->where(function ($q) use ($product, $variantIds): void {
                $q->where('product_id', $product->id);
                if ($variantIds !== []) {
                    $q->orWhereIn('variant_id', $variantIds);
                }
            })
            ->count();
    }

    /** @param array<int,string> $variantIds */
    private function invoiceLineCount(Product $product, array $variantIds): int
    {
        if (! DB::getSchemaBuilder()->hasColumn('facture_items', 'product_id')) {
            return 0;
        }

        return DB::table('facture_items')->where('product_id', $product->id)->count();
    }

    /** @param array<int,string> $variantIds */
    private function legacyStockLedgerCount(Product $product, array $variantIds): int
    {
        return StockLedger::withoutTenancy(fn () => StockLedger::query()
            ->where(function ($q) use ($product, $variantIds): void {
                $q->where('product_id', $product->id);
                if ($variantIds !== []) {
                    $q->orWhereIn('variant_id', $variantIds);
                }
            })
            ->count());
    }

    private function legacyStockNonZeroCount(Product $product): int
    {
        return Stock::withoutTenancy(fn () => Stock::query()
            ->where('product_id', $product->id)
            ->where(fn ($q) => $q->where('quantity', '!=', 0)->orWhere('reserved', '!=', 0))
            ->count());
    }

    /** @param array<int,string> $variantIds @return Collection<int,string> */
    private function resolveInventoryItemIds(Product $product, array $variantIds): Collection
    {
        $ids = collect();

        $productLinkItemId = InventoryItem::withoutOrganizationTenancy(
            fn () => ProductInventoryLink::query()->where('product_id', $product->id)->value('inventory_item_id')
        );
        if ($productLinkItemId !== null) {
            $ids->push($productLinkItemId);
        }

        if ($variantIds !== []) {
            $variantItemIds = InventoryItem::withoutOrganizationTenancy(
                fn () => VariantInventoryLink::query()->whereIn('product_variant_id', $variantIds)->pluck('inventory_item_id')
            );
            $ids = $ids->merge($variantItemIds);
        }

        return $ids->filter()->unique()->values();
    }
}
