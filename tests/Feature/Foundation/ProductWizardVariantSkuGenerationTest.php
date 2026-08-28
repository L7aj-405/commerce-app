<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function skuGenWorkspace(string $name = 'Sku Gen Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function skuGenProduct(Store $store, string $sku = 'ABC'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Sku Gen Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 199,
    ]));
}

it('auto-generates a SKU for a variant submitted with an empty SKU', function (): void {
    [, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, 'ABC');

    $result = app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['XS/S']],
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => '', 'price' => 199, 'options' => ['Size' => 'XS/S', 'Color' => 'Beige']],
    ]);

    $variant = $result['variants']->first();

    expect($variant->sku)->not->toBeEmpty()
        ->and($variant->sku)->toBe('ABC-XS-S-BEIGE');
});

it('falls back to a slugged product name prefix when the product has no SKU', function (): void {
    [, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, '');
    $product->update(['name' => 'Cozy Hoodie']);

    $result = app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => '', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ]);

    expect($result['variants']->first()->sku)->toBe('COZY-HOODIE-BEIGE');
});

it('preserves an existing non-empty SKU instead of overwriting it', function (): void {
    [, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, 'ABC');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => 'MY-CUSTOM-SKU', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ]);

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    expect($variant->sku)->toBe('MY-CUSTOM-SKU');

    // Re-saving without changes must not silently regenerate it.
    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['id' => $variant->id, 'sku' => 'MY-CUSTOM-SKU', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ]);

    expect($variant->fresh()->sku)->toBe('MY-CUSTOM-SKU');
});

it('overwrites existing active variant SKUs when regenerateSkus is requested', function (): void {
    [, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, 'ABC');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => 'MY-CUSTOM-SKU', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ]);

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['id' => $variant->id, 'sku' => 'MY-CUSTOM-SKU', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ], true);

    expect($variant->fresh()->sku)->toBe('ABC-BEIGE')
        ->and($variant->fresh()->sku)->not->toBe('MY-CUSTOM-SKU');
});

it('generates a unique SKU when the natural candidate is already taken by another variant', function (): void {
    [, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, 'ABC');

    // Pre-existing row occupying the exact SKU the generator would naturally
    // produce for a brand-new Color=Beige variant.
    ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Variant 1', 'sku' => 'ABC-BEIGE', 'price' => 199,
    ]));

    $result = app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => '', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ]);

    expect($result['variants']->first()->sku)->toBe('ABC-BEIGE-2');
});

it('generates unique SKUs for multiple new variants created in the same submission', function (): void {
    [, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, 'ABC');

    $result = app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => '', 'price' => 199, 'options' => ['Size' => 'S', 'Color' => 'Beige']],
        ['sku' => '', 'price' => 199, 'options' => ['Size' => 'M', 'Color' => 'Beige']],
    ]);

    $skus = $result['variants']->pluck('sku')->all();

    expect($skus)->toBe(['ABC-S-BEIGE', 'ABC-M-BEIGE'])
        ->and(count(array_unique($skus)))->toBe(2);
});

it('does not reset stock quantity to 0 when saving with regenerate_skus through the HTTP update endpoint', function (): void {
    [$owner, $store] = skuGenWorkspace();
    $product = skuGenProduct($store, 'ABC');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Beige']],
    ], [
        ['sku' => 'ABC-BEIGE-ORIG', 'price' => 199, 'options' => ['Color' => 'Beige']],
    ]);

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();
    Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'quantity' => 15, 'reorder_level' => 10]);

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'variable', 'price' => 199, 'status' => 'active',
        'options' => [['name' => 'Color', 'values' => ['Beige']]],
        'variants' => [['id' => $variant->id, 'sku' => 'ABC-BEIGE-ORIG', 'price' => 199, 'options' => ['Color' => 'Beige']]],
        'regenerate_skus' => true,
    ])->assertSessionHasNoErrors();

    expect((int) Stock::query()->where('variant_id', $variant->id)->value('quantity'))->toBe(15);
});
