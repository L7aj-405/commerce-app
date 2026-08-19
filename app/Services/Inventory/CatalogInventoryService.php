<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\WarehouseInventoryBalance;
use App\Models\VariantInventoryLink;
use Illuminate\Validation\ValidationException;

class CatalogInventoryService
{
    public function forCatalog(Product $product, ?ProductVariant $variant = null): ?InventoryItem
    {
        $product->loadMissing('store');
        $organizationId = $product->store?->organization_id;
        if ($organizationId === null) {
            return null;
        }

        if ($variant !== null && $variant->product_id !== $product->id) {
            throw ValidationException::withMessages(['variant' => 'Variant does not belong to the product.']);
        }

        $existing = $variant
            ? VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()
                ->where('product_variant_id', $variant->id)->with('inventoryItem')->first())
            : ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()
                ->where('product_id', $product->id)->with('inventoryItem')->first());

        if ($existing?->inventoryItem !== null) {
            return $existing->inventoryItem;
        }

        $sku = trim((string) ($variant?->sku ?: $product->sku));
        if ($sku === '') {
            $sku = $variant ? "VAR-{$variant->id}" : "PRD-{$product->id}";
        }
        $name = $variant ? trim($product->name . ' - ' . $variant->getDisplayName()) : $product->name;
        $barcode = $variant?->barcode ?? $product->barcode;

        return InventoryItem::withoutOrganizationTenancy(function () use ($organizationId, $sku, $name, $barcode, $product, $variant): InventoryItem {
            $item = InventoryItem::withTrashed()->firstOrCreate(
                ['organization_id' => $organizationId, 'sku' => $sku],
                ['name' => $name, 'barcode' => $barcode, 'is_active' => true],
            );
            if ($item->trashed()) {
                $item->restore();
                $item->update(['is_active' => true]);
            }

            if ($variant !== null) {
                VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::updateOrCreate(
                    ['product_variant_id' => $variant->id],
                    ['organization_id' => $organizationId, 'inventory_item_id' => $item->id, 'units_per_sale' => 1],
                ));
            } else {
                ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::updateOrCreate(
                    ['product_id' => $product->id],
                    ['organization_id' => $organizationId, 'inventory_item_id' => $item->id, 'units_per_sale' => 1],
                ));
            }

            $this->projectExistingBalances($item, $product, $variant);

            return $item;
        });
    }

    private function projectExistingBalances(InventoryItem $item, Product $product, ?ProductVariant $variant): void
    {
        WarehouseInventoryBalance::withoutOrganizationTenancy(function () use ($item, $product, $variant): void {
            WarehouseInventoryBalance::query()
                ->where('inventory_item_id', $item->id)
                ->get()
                ->each(function (WarehouseInventoryBalance $balance) use ($product, $variant): void {
                    Stock::withoutTenancy(fn () => Stock::withoutEvents(fn () => Stock::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'variant_id' => $variant?->id,
                            'warehouse_id' => $balance->warehouse_id,
                        ],
                        [
                            'quantity' => $balance->available(),
                            'reserved' => 0,
                        ],
                    )));
                });
        });
    }

    public function resolve(?string $productId, ?string $variantId): ?InventoryItem
    {
        if ($variantId !== null) {
            $link = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()
                ->where('product_variant_id', $variantId)->with('inventoryItem')->first());
            if ($link?->inventoryItem) return $link->inventoryItem;
        }

        if ($productId === null) return null;
        $link = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()
            ->where('product_id', $productId)->with('inventoryItem')->first());
        if ($link?->inventoryItem) return $link->inventoryItem;

        $product = Product::withoutTenancy(fn () => Product::query()->find($productId));
        if ($product === null) return null;
        $variant = $variantId ? ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($variantId)) : null;
        return $this->forCatalog($product, $variant);
    }

    /**
     * Resolve the stocked item for an order line using every safe identifier we
     * may have: local product/variant ids, then canonical SKU inside the order's
     * organization/store. Real online orders are not guaranteed to carry local
     * IDs, and tests may run without an active tenant context, so the caller
     * passes the explicit organization/store boundary.
     */
    public function resolveOrderLine(
        ?string $productId,
        ?string $variantId,
        ?string $sku,
        ?string $organizationId,
        ?string $storeId,
    ): ?InventoryItem {
        $resolved = $this->resolve($productId, $variantId);

        if ($resolved !== null) {
            return $resolved;
        }

        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        if ($organizationId !== null) {
            $item = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $sku)
                ->where('is_active', true)
                ->first());

            if ($item !== null) {
                return $item;
            }
        }

        if ($storeId === null) {
            return null;
        }

        $product = Product::withoutTenancy(fn () => Product::query()
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->first());

        if ($product !== null) {
            return $this->forCatalog($product);
        }

        $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()
            ->where('sku', $sku)
            ->whereHas('product', fn ($query) => $query->where('store_id', $storeId))
            ->first());

        if ($variant === null) {
            return null;
        }

        $product = Product::withoutTenancy(fn () => Product::query()->find($variant->product_id));

        return $product !== null ? $this->forCatalog($product, $variant) : null;
    }
}
