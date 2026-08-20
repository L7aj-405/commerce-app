<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function noWriteStockWorkspace(string $name = 'No Write Stock Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('does not overwrite stock quantity when the product core fields are edited', function (): void {
    [$owner, $store] = noWriteStockWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Simple Stock Product', 'sku' => 'NWS-SIMPLE-1', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));
    $warehouse = $store->getPrimaryWarehouse();
    Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'variant_id' => null, 'quantity' => 12, 'reorder_level' => 10]);

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Renamed Simple Product', 'sku' => 'NWS-SIMPLE-1', 'type' => 'simple', 'price' => 75, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect((int) Stock::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'))->toBe(12);
});

it('does not reset variant stock to 0 when variants are regenerated on save', function (): void {
    [$owner, $store] = noWriteStockWorkspace();

    $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'Variable Stock Product 2', 'sku' => 'NWS-VAR-2', 'type' => 'variable', 'price' => 80,
        'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
        'variants' => [
            ['sku' => 'NWS-VAR-2-S', 'price' => 80, 'stock' => 0, 'options' => ['Size' => 'S']],
            ['sku' => 'NWS-VAR-2-M', 'price' => 80, 'stock' => 0, 'options' => ['Size' => 'M']],
        ],
    ])->assertSessionHasNoErrors();

    $created = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'NWS-VAR-2')->firstOrFail());
    $variantS = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $created->id)->where('sku', 'NWS-VAR-2-S')->firstOrFail());
    $variantM = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $created->id)->where('sku', 'NWS-VAR-2-M')->firstOrFail());

    // Simulate real stock the inventory engine has since recorded — a plain
    // save/regenerate must never stomp on this.
    $warehouse = $store->getPrimaryWarehouse();
    Stock::query()->where('product_id', $created->id)->where('variant_id', $variantS->id)->update(['quantity' => 20]);
    Stock::query()->where('product_id', $created->id)->where('variant_id', $variantM->id)->update(['quantity' => 15]);

    // Edit the product and regenerate variants (same combos, e.g. price bump).
    $this->actingAs($owner)->patch("/dashboard/products/{$created->id}", [
        'name' => 'Variable Stock Product 2', 'sku' => 'NWS-VAR-2', 'type' => 'variable', 'price' => 90, 'status' => 'active',
        'options' => [['name' => 'Size', 'values' => ['S', 'M']]],
        'variants' => [
            ['id' => $variantS->id, 'sku' => 'NWS-VAR-2-S', 'price' => 90, 'options' => ['Size' => 'S']],
            ['id' => $variantM->id, 'sku' => 'NWS-VAR-2-M', 'price' => 90, 'options' => ['Size' => 'M']],
        ],
    ])->assertSessionHasNoErrors();

    expect((int) Stock::query()->where('variant_id', $variantS->id)->where('warehouse_id', $warehouse->id)->value('quantity'))->toBe(20)
        ->and((int) Stock::query()->where('variant_id', $variantM->id)->where('warehouse_id', $warehouse->id)->value('quantity'))->toBe(15);
});
