<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\InventoryItem;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\VariantInventoryLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mutating half of the imported-product cleanup toolkit. Every method takes
 * already store-scoped Product models — callers (controller/artisan command)
 * are responsible for that scoping, this service never re-derives it.
 *
 * archive/unlink are always safe (no history is touched). purge is gated by
 * ProductCleanupSafetyService::check() — a product failing the check is
 * skipped, never partially deleted.
 */
class ProductCleanupService
{
    public function __construct(private readonly ProductCleanupSafetyService $safety)
    {
    }

    /** @param Collection<int,Product> $products @return array<int,array{product_id:string}> */
    public function archive(Collection $products): array
    {
        $results = [];

        foreach ($products as $product) {
            $product->update(['status' => 'archived']);
            $results[] = ['product_id' => $product->id, 'archived' => true];
        }

        return $results;
    }

    /** @param Collection<int,Product> $products @return array<int,array{product_id:string,unlinked:bool}> */
    public function unlinkFromConnection(Collection $products, PlatformConnection $connection): array
    {
        return $this->detachConnection($products, $connection, resetSyncedAt: false);
    }

    /** @param Collection<int,Product> $products @return array<int,array{product_id:string,unlinked:bool}> */
    public function resetSyncForConnection(Collection $products, PlatformConnection $connection): array
    {
        return $this->detachConnection($products, $connection, resetSyncedAt: true);
    }

    /**
     * Reset sync mapping across EVERY connection each product currently has a
     * mapping for — used by the "Reset mappings for skipped products" bulk
     * action, where the caller doesn't know (or care) which connection(s)
     * each skipped product happens to be linked to.
     *
     * @param Collection<int,Product> $products
     * @return array<int,array{product_id:string,connections_reset:int}>
     */
    public function resetAllSyncMappings(Collection $products): array
    {
        $results = [];

        foreach ($products as $product) {
            $product->loadMissing(['channelListings', 'variants.channelListings']);

            $connectionIds = $product->channelListings->pluck('platform_connection_id')
                ->merge($product->variants->flatMap(fn ($v) => $v->channelListings->pluck('platform_connection_id')))
                ->unique()
                ->values();

            $reset = 0;
            foreach ($connectionIds as $connectionId) {
                $connection = PlatformConnection::query()->find($connectionId);
                if ($connection === null) {
                    continue;
                }

                $this->detachConnection(collect([$product]), $connection, resetSyncedAt: true);
                $reset++;
            }

            $results[] = ['product_id' => $product->id, 'connections_reset' => $reset];
        }

        return $results;
    }

    /** @param Collection<int,Product> $products */
    private function detachConnection(Collection $products, PlatformConnection $connection, bool $resetSyncedAt): array
    {
        $results = [];

        DB::transaction(function () use ($products, $connection, $resetSyncedAt, &$results): void {
            foreach ($products as $product) {
                $listing = ProductChannelListing::query()
                    ->where('product_id', $product->id)
                    ->where('platform_connection_id', $connection->id)
                    ->first();

                $hadListing = $listing !== null;

                // Cascades to product_variant_channel_listings automatically.
                $listing?->delete();

                // Legacy fallback columns (`products.platform`/`external_id`) let
                // externalIdForConnection() resurrect a mapping even after the
                // listing row is gone. Clear them only when they belong to THIS
                // connection's platform so an unrelated platform's legacy data
                // is never touched.
                if ($product->platform === $connection->platform) {
                    $product->forceFill(['external_id' => null, 'platform' => null]);
                    if ($resetSyncedAt) {
                        $product->forceFill(['synced_at' => null]);
                    }
                    $product->save();

                    ProductVariant::where('product_id', $product->id)->update(['external_id' => null]);
                }

                $results[] = ['product_id' => $product->id, 'unlinked' => $hadListing];
            }
        });

        return $results;
    }

    /**
     * Purge every safe product in the set. Each product is re-checked here
     * (never trusts a stale preview) — a blocked product is skipped and its
     * blockers are reported, never partially deleted.
     *
     * @param Collection<int,Product> $products
     * @return array<int,array{product_id:string,name:string,sku:?string,purged:bool,blockers:array<int,string>,recommended_action:?string,recommended_action_label:?string,recommended_connection_id:?string}>
     */
    public function purge(Collection $products): array
    {
        return $products->map(fn (Product $product) => $this->purgeOne($product))->values()->all();
    }

    /** @return array{product_id:string,name:string,sku:?string,purged:bool,blockers:array<int,string>,recommended_action:?string,recommended_action_label:?string,recommended_connection_id:?string} */
    public function purgeOne(Product $product): array
    {
        $check = $this->safety->check($product);

        if (! $check['can_purge']) {
            return [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'purged' => false,
                'blockers' => $check['blockers'],
                'recommended_action' => $check['recommended_action'],
                'recommended_action_label' => $check['recommended_action_label'],
                'recommended_connection_id' => $check['recommended_connection_id'],
            ];
        }

        DB::transaction(function () use ($product): void {
            $variantIds = ProductVariant::withTrashed()->where('product_id', $product->id)->pluck('id')->all();

            $inventoryItemIds = collect();
            $productLinkItemId = InventoryItem::withoutOrganizationTenancy(
                fn () => ProductInventoryLink::query()->where('product_id', $product->id)->value('inventory_item_id')
            );
            if ($productLinkItemId !== null) {
                $inventoryItemIds->push($productLinkItemId);
            }
            if ($variantIds !== []) {
                $inventoryItemIds = $inventoryItemIds->merge(InventoryItem::withoutOrganizationTenancy(
                    fn () => VariantInventoryLink::query()->whereIn('product_variant_id', $variantIds)->pluck('inventory_item_id')
                ));
            }
            $inventoryItemIds = $inventoryItemIds->filter()->unique()->values();

            // stock_movements.product_id has no cascade/null-on-delete (RESTRICT)
            // — must be cleared manually before the product can be force-deleted.
            // Safe to remove outright: the safety check already confirmed there
            // is no order/ledger/return/transfer history for this product.
            StockMovement::query()
                ->where('product_id', $product->id)
                ->when($variantIds !== [], fn ($q) => $q->orWhereIn('variant_id', $variantIds))
                ->delete();

            Stock::withoutTenancy(fn () => Stock::query()->where('product_id', $product->id)->delete());

            // Everything else (variants, channel listings, variant channel
            // listings, attributes/values, inventory links) cascades from the
            // products/product_variants foreign keys.
            ProductVariant::withTrashed()->where('product_id', $product->id)->get()->each->forceDelete();
            $product->forceDelete();

            foreach ($inventoryItemIds as $itemId) {
                $stillLinked = InventoryItem::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('inventory_item_id', $itemId)->exists()
                    || VariantInventoryLink::query()->where('inventory_item_id', $itemId)->exists());

                if (! $stillLinked) {
                    InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::withTrashed()->find($itemId)?->delete());
                }
            }
        });

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'purged' => true,
            'blockers' => [],
            'recommended_action' => null,
            'recommended_action_label' => null,
            'recommended_connection_id' => null,
        ];
    }
}
