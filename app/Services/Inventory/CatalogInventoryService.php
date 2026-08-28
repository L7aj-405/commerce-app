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

            // A specific variant was identified — resolve/create ITS OWN
            // inventory item and never fall through to the parent product's
            // ProductInventoryLink below. That link can be stale (e.g. left
            // over from before this product had variants, or from an
            // earlier order line that matched at product level), and
            // silently reusing it binds this variant's shortage to the
            // wrong item — one a variant-level stock adjustment can never
            // top up, which is exactly why a waiting order never released
            // after the matching variant's stock was set.
            $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($variantId));
            if ($variant === null) return null;
            $product = Product::withoutTenancy(fn () => Product::query()->find($variant->product_id));
            return $product !== null ? $this->forCatalog($product, $variant) : null;
        }

        if ($productId === null) return null;
        $link = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()
            ->where('product_id', $productId)->with('inventoryItem')->first());
        if ($link?->inventoryItem) return $link->inventoryItem;

        $product = Product::withoutTenancy(fn () => Product::query()->find($productId));
        return $product !== null ? $this->forCatalog($product) : null;
    }
}
