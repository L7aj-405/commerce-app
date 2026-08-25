<?php

declare(strict_types=1);

use App\Models\InventoryLedgerEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Models\WarehouseInventoryBalance;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\OrganizationProvisioner;

/**
 * The Product Edit variant table must show the inventory engine's source of
 * truth (InventoryItem -> WarehouseInventoryBalance), never a stale/legacy
 * number — and must never let a not-yet-saved variant be adjusted.
 */

/** @return array{0: User, 1: Store} */
function pevsWorkspace(string $name = 'Product Edit Variant Stock Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pevsVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Stock Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 45,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 45, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 47, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

/** Finds the variant matching $variantId inside the Inertia `product.variants` prop array. */
function pevsFindVariant(iterable $variants, string $variantId): ?array
{
    foreach ($variants as $variant) {
        if (($variant['id'] ?? null) === $variantId) {
            return $variant;
        }
    }

    return null;
}

it('returns variant stock_on_hand from WarehouseInventoryBalance on Product Edit (test 1)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-1');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'PEVS-1-S')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 18, 'adjustment', null, null, 'Initial count', false);

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Products/Edit')
            ->has('product.variants', 2)
            ->where('product.variants', function ($variants) use ($variant, $warehouse) {
                $target = pevsFindVariant($variants, $variant->id);

                return $target !== null
                    && $target['stock_on_hand'] === 18
                    && $target['stock_available'] === 18
                    && $target['inventory_missing'] === false
                    && $target['warehouse_id'] === $warehouse->id;
            }));
});

it('does not show 0 for a variant that has a real balance (test 2)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-2');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'PEVS-2-M')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 7, 'adjustment', null, null, 'Initial count', false);

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.variants', function ($variants) use ($variant) {
            $target = pevsFindVariant($variants, $variant->id);

            return $target !== null && $target['stock_on_hand'] !== 0 && $target['stock_on_hand'] === 7;
        }));
});

it('shows the correct on-hand quantity for a variant with no prior adjustment (genuinely zero, not stale)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-ZERO');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'PEVS-ZERO-S')->firstOrFail());

    // No Stock row, no InventoryItem link, no WarehouseInventoryBalance —
    // nothing has ever touched this variant's inventory. 0 is the honest
    // answer, and inventory_missing tells the UI/caller that's because
    // nothing has been recorded yet, not that data went stale.
    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.variants', function ($variants) use ($variant) {
            $target = pevsFindVariant($variants, $variant->id);

            return $target !== null && $target['stock_on_hand'] === 0 && $target['inventory_missing'] === true;
        }));
});

it('does not reset an existing variant stock balance when variants are regenerated (test 3)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-3');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'PEVS-3-S')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 25, 'adjustment', null, null, 'Initial count', false);

    // Re-generate the exact same combinations again (what the "Generate
    // variants" UI action submits) — the wizard never touches stock.
    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => 'PEVS-3-S', 'price' => 45, 'options' => ['Size' => 'S']],
        ['sku' => 'PEVS-3-M', 'price' => 47, 'options' => ['Size' => 'M']],
    ]);

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.variants', function ($variants) use ($variant) {
            $target = pevsFindVariant($variants, $variant->id);

            return $target !== null && $target['stock_on_hand'] === 25;
        }));

    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());
    expect($balance->on_hand)->toBe(25);
});

it('rejects adjusting stock for a variant id that was never actually saved (test 4)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-4');
    $warehouse = $store->getPrimaryWarehouse();

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 5,
        'reason' => 'Should fail', 'variant_id' => '01HNOTREALVARIANTID0000000',
    ])->assertNotFound();
});

it('updates WarehouseInventoryBalance for a variant stock adjustment (test 5)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-5');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'PEVS-5-S')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 14, 'reason' => 'Recount', 'variant_id' => $variant->id,
    ])->assertSessionHasNoErrors();

    $item = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $variant->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(14);
});

it('writes an InventoryLedgerEntry for a variant stock adjustment (test 6)', function (): void {
    [$owner, $store] = pevsWorkspace();
    $product = pevsVariableProduct($store, 'PEVS-6');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'PEVS-6-M')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 6, 'reason' => 'Recount', 'variant_id' => $variant->id,
    ])->assertSessionHasNoErrors();

    $item = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $variant->id)->firstOrFail())->inventoryItem;

    expect(InventoryLedgerEntry::withoutOrganizationTenancy(fn () => InventoryLedgerEntry::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->exists()))->toBeTrue();
});
