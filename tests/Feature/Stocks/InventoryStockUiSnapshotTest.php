<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\OrganizationProvisioner;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Inventory Stock UI Operational Transparency — the Stock dashboard's props
 * must carry the InventoryEngine's own on_hand/reserved/available numbers
 * (WarehouseInventoryBalance), never the legacy `stocks` table or
 * ProductVariant/Product quantity columns (which don't even exist as a
 * source of truth here) as the PRIMARY figure.
 */

/** @return array{0: User, 1: Store} */
function isusWorkspace(string $name = 'Stock UI Snapshot Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('includes on_hand, reserved and available for a simple product', function (): void {
    [$owner, $store] = isusWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Simple Snapshot', 'sku' => 'ISUS-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, $owner, 'Initial count', false);
    app(InventoryEngine::class)->reserve($item, $warehouse, 3, null, $owner, 'Reserved for an order');

    $this->actingAs($owner)->get('/dashboard/stock')
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.on_hand', 10)
            ->where('products.data.0.reserved', 3)
            ->where('products.data.0.available', 7));
});

it('includes on_hand, reserved, available and waiting_demand per variant', function (): void {
    [$owner, $store] = isusWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Snapshot', 'sku' => 'ISUS-2', 'type' => 'variable', 'status' => 'active', 'price' => 60,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'ISUS-2-S', 'price' => 60, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, $owner, 'Initial count', false);

    $this->actingAs($owner)->get('/dashboard/stock')
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.variants.0.on_hand', 5)
            ->where('products.data.0.variants.0.reserved', 0)
            ->where('products.data.0.variants.0.available', 5)
            ->where('products.data.0.variants.0.waiting_demand', 0));
});

it('never uses legacy ProductVariant/Product quantity as the primary stock number', function (): void {
    [$owner, $store] = isusWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Divergent Snapshot', 'sku' => 'ISUS-3', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    // The engine's real on_hand is 25 — if the UI ever fell back to a stale
    // legacy number it would show something else (the legacy `stocks` row
    // this same call keeps mirrored to `available()`, i.e. would read as a
    // DIFFERENT number once something is reserved).
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 25, 'adjustment', null, $owner, 'Initial count', false);
    app(InventoryEngine::class)->reserve($item, $warehouse, 9, null, $owner, 'Reserved for an order');

    $this->actingAs($owner)->get('/dashboard/stock')
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.on_hand', 25)      // NOT 16 (the legacy `available` projection)
            ->where('products.data.0.available', 16));  // on_hand - reserved
});
