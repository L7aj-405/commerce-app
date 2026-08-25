<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;

/**
 * The single, platform-agnostic resolver for "what local catalog record —
 * and, if resolvable, what InventoryItem — does this online order line
 * actually refer to?" Used by OrderLineItems::for() (display + the
 * confirmation-time "unmapped" guard) and by WarehouseAllocationService
 * (shortage/reservation creation) — one set of rules, never two that can
 * silently disagree.
 *
 * Platform connectors normalize their raw order payloads to plain
 * external_product_id / external_variant_id / sku strings before this is
 * called (ShopifyConnector::parseOrder emits `variant_id`;
 * WooCommerceConnector::parseOrder emits `variant_id` from `variation_id`),
 * so this class itself never talks to a platform API and never needs to
 * know which platform it's resolving for beyond what's already in
 * platform_connection_id — the exact same call shape will work for a future
 * YouCan importer once one exists.
 *
 * Priority, matching the Waiting Stock mapping spec:
 *   1. Variant channel listing (external_variant_id)
 *   2. Product channel listing (external_product_id) — simple products
 *      resolve directly; variable products must then resolve a SPECIFIC
 *      variant (via a scoped variant listing or a unique SKU match) — a
 *      variable product NEVER falls back to a product-level InventoryItem.
 *   3. Store-wide SKU fallback (variant SKU first, then product SKU — but
 *      only for a genuinely simple product).
 *   4. Unmapped.
 */
class OrderLineInventoryResolver
{
    public function __construct(private readonly CatalogInventoryService $catalog) {}

    public function resolve(
        ?string $organizationId,
        string $storeId,
        ?string $platformConnectionId,
        ?string $externalProductId,
        ?string $externalVariantId,
        ?string $sku,
    ): OrderLineInventoryResolution {
        $externalProductId = $this->normalize($externalProductId);
        $externalVariantId = $this->normalize($externalVariantId);
        $sku = $this->normalize($sku);

        $resolution = $this->doResolve($storeId, $platformConnectionId, $externalProductId, $externalVariantId, $sku);

        // Defense-in-depth against a cross-store/cross-organization match —
        // every query above is already store/connection scoped, but this
        // guarantees no path can ever leak a record outside this boundary.
        // The organization check only applies when an organization id is
        // actually known (a store with no organization yet can still
        // resolve product/variant identity for display — CatalogInventoryService
        // ::forCatalog() is the one place that already refuses to create an
        // InventoryItem without an organization).
        if ($resolution->productId !== null) {
            $product = Product::withoutTenancy(fn () => Product::query()->with('store')->find($resolution->productId));

            if ($product === null || $product->store_id !== $storeId
                || ($organizationId !== null && $product->store?->organization_id !== $organizationId)) {
                return OrderLineInventoryResolution::unmapped('Resolved product does not belong to this order\'s store/organization.');
            }
        }

        return $resolution;
    }

    private function doResolve(
        string $storeId,
        ?string $platformConnectionId,
        ?string $externalProductId,
        ?string $externalVariantId,
        ?string $sku,
    ): OrderLineInventoryResolution {
        // 1. Variant listing match.
        if ($platformConnectionId !== null && $externalVariantId !== null) {
            $listing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
                ->where('platform_connection_id', $platformConnectionId)
                ->where('external_variant_id', $externalVariantId)
                ->first());

            if ($listing !== null) {
                $item = $this->itemForVariant($listing->product_variant_id);

                return new OrderLineInventoryResolution(
                    $listing->product_id,
                    $listing->product_variant_id,
                    $item,
                    OrderLineInventoryResolution::SOURCE_VARIANT_LISTING,
                );
            }
        }

        // 2. Product listing match.
        if ($platformConnectionId !== null && $externalProductId !== null) {
            $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
                ->where('platform_connection_id', $platformConnectionId)
                ->where('external_product_id', $externalProductId)
                ->first());

            if ($productListing !== null) {
                $product = Product::withoutTenancy(fn () => Product::query()->find($productListing->product_id));

                if ($product !== null) {
                    return $this->resolveViaProductListing($product, $platformConnectionId, $externalVariantId, $sku, OrderLineInventoryResolution::SOURCE_PRODUCT_LISTING_SIMPLE);
                }
            }
        }

        // 2b. Legacy Product/ProductVariant.external_id match — predates the
        // ProductChannelListing tables; kept for orders synced before a
        // listing backfill. Scoped by the connection's own `platform` when
        // one is known (never cross-platform), unscoped only when the order
        // carries no connection at all (pre-connection legacy data).
        $platform = $this->platformFor($platformConnectionId);

        if ($externalVariantId !== null) {
            $legacyVariant = $this->legacyVariant($storeId, $platform, $externalVariantId);

            if ($legacyVariant !== null) {
                $product = Product::withoutTenancy(fn () => Product::query()->find($legacyVariant->product_id));

                if ($product !== null) {
                    return new OrderLineInventoryResolution(
                        $product->id, $legacyVariant->id,
                        $this->catalog->forCatalog($product, $legacyVariant),
                        OrderLineInventoryResolution::SOURCE_LEGACY_EXTERNAL_ID,
                    );
                }
            }
        }

        if ($externalProductId !== null) {
            $legacyProduct = $this->legacyProduct($storeId, $platform, $externalProductId);

            if ($legacyProduct !== null) {
                // Same product/variant sub-resolution a channel listing match
                // would use — a variable product matched only at product
                // level here must still land on a specific variant, never a
                // product-level InventoryItem.
                return $this->resolveViaProductListing($legacyProduct, $platformConnectionId, $externalVariantId, $sku, OrderLineInventoryResolution::SOURCE_LEGACY_EXTERNAL_ID);
            }
        }

        // 2c. The "external_product_id" might already BE a local Product
        // ULID — some order lines are built directly against local ids
        // rather than a platform's own ids (e.g. hand-built/legacy fixtures,
        // or a future direct-entry flow). A real platform id essentially
        // never collides with a 26-char ULID, so this is safe to check
        // unconditionally, not just when nothing else matched.
        if ($externalProductId !== null) {
            $localProduct = Product::withoutTenancy(fn () => Product::query()
                ->where('store_id', $storeId)->where('id', $externalProductId)->first());

            if ($localProduct !== null) {
                return $this->resolveViaProductListing($localProduct, $platformConnectionId, $externalVariantId, $sku, OrderLineInventoryResolution::SOURCE_LOCAL);
            }
        }

        // 3. SKU fallback (no listing or legacy id matched at all).
        if ($sku !== null) {
            $bySku = $this->resolveBySku($storeId, $sku);

            if ($bySku !== null) {
                return $bySku;
            }
        }

        // 4. Unmapped.
        return OrderLineInventoryResolution::unmapped('Order line is not linked to a local inventory item.');
    }

    private function platformFor(?string $platformConnectionId): ?string
    {
        if ($platformConnectionId === null) {
            return null;
        }

        return PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($platformConnectionId))?->platform;
    }

    private function legacyProduct(string $storeId, ?string $platform, string $externalId): ?Product
    {
        return Product::withoutTenancy(fn () => Product::query()
            ->where('store_id', $storeId)
            ->when($platform !== null, fn ($q) => $q->where('platform', $platform))
            ->where('external_id', $externalId)
            ->first());
    }

    private function legacyVariant(string $storeId, ?string $platform, string $externalId): ?ProductVariant
    {
        return ProductVariant::withoutTenancy(fn () => ProductVariant::query()
            ->whereHas('product', function ($q) use ($storeId, $platform): void {
                $q->where('store_id', $storeId);
                if ($platform !== null) {
                    $q->where('platform', $platform);
                }
            })
            ->where('external_id', $externalId)
            ->first());
    }

    /**
     * A product was matched (via channel listing or the legacy external_id
     * path) — a simple product resolves directly; a variable one must then
     * land on a specific variant (never a product-level InventoryItem).
     * $simpleSource lets the caller (listing vs. legacy match) tag which
     * tier actually produced the match without duplicating this logic.
     */
    private function resolveViaProductListing(Product $product, ?string $platformConnectionId, ?string $externalVariantId, ?string $sku, string $simpleSource): OrderLineInventoryResolution
    {
        if (! $product->isVariable()) {
            // Local InventoryItem always comes from the product-level link
            // (CatalogInventoryService::forCatalog) — NOT from
            // ProductChannelListing.metadata['default_inventory_item_id'],
            // which is the platform's OWN remote inventory item id (used
            // only when pushing stock out), not a local InventoryItem ULID.
            return new OrderLineInventoryResolution(
                $product->id,
                null,
                $this->catalog->forCatalog($product),
                $simpleSource,
            );
        }

        if ($platformConnectionId !== null && $externalVariantId !== null) {
            $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
                ->where('platform_connection_id', $platformConnectionId)
                ->where('external_variant_id', $externalVariantId)
                ->where('product_id', $product->id)
                ->first());

            if ($variantListing !== null) {
                return new OrderLineInventoryResolution(
                    $product->id,
                    $variantListing->product_variant_id,
                    $this->itemForVariant($variantListing->product_variant_id),
                    OrderLineInventoryResolution::SOURCE_PRODUCT_LISTING_VARIANT,
                );
            }
        }

        if ($sku !== null) {
            $variants = ProductVariant::withoutTenancy(fn () => ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('sku', $sku)
                ->limit(2)
                ->get());

            if ($variants->count() === 1) {
                $variant = $variants->first();

                return new OrderLineInventoryResolution(
                    $product->id,
                    $variant->id,
                    $this->catalog->forCatalog($product, $variant),
                    OrderLineInventoryResolution::SOURCE_PRODUCT_LISTING_VARIANT,
                );
            }

            if ($variants->count() > 1) {
                return new OrderLineInventoryResolution(
                    $product->id, null, null, OrderLineInventoryResolution::SOURCE_AMBIGUOUS,
                    'SKU matches multiple variants on this product; manual mapping required.',
                );
            }
        }

        // Variable product, no variant resolvable — NEVER fall back to a
        // product-level InventoryItem here (that's the exact bug this
        // resolver exists to prevent: a variant-level stock adjustment can
        // never top up a product-level item).
        return new OrderLineInventoryResolution(
            $product->id, null, null, OrderLineInventoryResolution::SOURCE_UNMAPPED,
            'Variable product order line could not be linked to a local variant.',
        );
    }

    /** No listing matched at all — last resort, store-wide SKU search. */
    private function resolveBySku(string $storeId, string $sku): ?OrderLineInventoryResolution
    {
        // product_variants.sku is only unique PER PRODUCT — two different
        // products in the same store may legitimately share a variant SKU.
        $variants = ProductVariant::withoutTenancy(fn () => ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('store_id', $storeId))
            ->where('sku', $sku)
            ->limit(2)
            ->get());

        if ($variants->count() === 1) {
            $variant = $variants->first();
            $product = Product::withoutTenancy(fn () => Product::query()->find($variant->product_id));

            if ($product !== null) {
                return new OrderLineInventoryResolution(
                    $product->id, $variant->id,
                    $this->catalog->forCatalog($product, $variant),
                    OrderLineInventoryResolution::SOURCE_SKU_VARIANT_FALLBACK,
                );
            }
        }

        if ($variants->count() > 1) {
            return new OrderLineInventoryResolution(
                null, null, null, OrderLineInventoryResolution::SOURCE_AMBIGUOUS,
                'SKU matches multiple variants; manual mapping required.',
            );
        }

        // products.sku is unique per store, so at most one row.
        $product = Product::withoutTenancy(fn () => Product::query()
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->first());

        if ($product === null) {
            return null; // let the caller fall through to the generic "unmapped" message
        }

        if ($product->isVariable()) {
            // Never use a variable product's OWN sku as a stand-in for one
            // of its variants — only a truly simple product may match here.
            return new OrderLineInventoryResolution(
                $product->id, null, null, OrderLineInventoryResolution::SOURCE_UNMAPPED,
                'SKU matched a variable product directly; a specific variant could not be determined.',
            );
        }

        return new OrderLineInventoryResolution(
            $product->id, null,
            $this->catalog->forCatalog($product),
            OrderLineInventoryResolution::SOURCE_SKU_PRODUCT_FALLBACK,
        );
    }

    private function itemForVariant(string $variantId): ?InventoryItem
    {
        $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($variantId));

        if ($variant === null) {
            return null;
        }

        $product = Product::withoutTenancy(fn () => Product::query()->find($variant->product_id));

        return $product !== null ? $this->catalog->forCatalog($product, $variant) : null;
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
