<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\OrganizationProvisioner;

/**
 * Phase S5 — ProductStockSnapshotService is the single source of truth for
 * stock display, for BOTH simple products and variants:
 * InventoryItem -> WarehouseInventoryBalance, never a stale legacy sum.
 * Before this phase, a simple product's Product Edit `total_stock` was still
 * computed from the legacy sellableStocks() sum even though variants had
 * already been migrated to the inventory engine — this closes that gap.
 */

/** @return array{0: User, 1: Store} */
function pssWorkspace(string $name = 'Product Stock Snapshot Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('shows a simple product total_stock from WarehouseInventoryBalance on Product Edit, not the legacy stocks sum', function (): void {
    [$owner, $store] = pssWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Snapshot Simple', 'sku' => 'PSS-1', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 42, 'adjustment', null, null, 'Initial count', false);

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.total_stock', 42)
            ->where('product.stock_on_hand', 42)
            ->where('product.stock_available', 42)
            ->where('product.inventory_missing', false)
            ->where('product.warehouse_id', $warehouse->id));
});

it('shows 0 and inventory_missing true for a simple product that has never had stock recorded', function (): void {
    [$owner, $store] = pssWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Snapshot Simple Zero', 'sku' => 'PSS-2', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.total_stock', 0)
            ->where('product.inventory_missing', true));
});

it('does not fall back to a stale legacy stocks row once a real WarehouseInventoryBalance exists for a simple product', function (): void {
    [$owner, $store] = pssWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Snapshot Simple Legacy', 'sku' => 'PSS-3', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    // A stale legacy Stock row with a DIFFERENT quantity than the real
    // balance — the balance must win, never the legacy row, once it exists.
    \App\Models\Stock::withoutTenancy(fn () => \App\Models\Stock::create([
        'product_id' => $product->id, 'variant_id' => null, 'warehouse_id' => $warehouse->id, 'quantity' => 999, 'reorder_level' => 10,
    ]));

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Recount', false);

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.total_stock', 5));
});

it('keeps variant stock snapshots correct for a variable product alongside the simple-product snapshot (regression)', function (): void {
    [$owner, $store] = pssWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Snapshot Variable', 'sku' => 'PSS-4', 'type' => 'variable', 'status' => 'active', 'price' => 45,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'PSS-4-S', 'price' => 45, 'options' => ['Size' => 'S']],
    ]);
    $variant = \App\Models\ProductVariant::withoutTenancy(fn () => \App\Models\ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 11, 'adjustment', null, null, 'Initial count', false);

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.variants', function ($variants) use ($variant) {
            foreach ($variants as $row) {
                if (($row['id'] ?? null) === $variant->id) {
                    return $row['stock_on_hand'] === 11 && $row['stock_available'] === 11 && $row['inventory_missing'] === false;
                }
            }

            return false;
        }));
});
