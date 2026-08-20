<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function removalWorkspace(string $name = 'Removal Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function removalProduct(Store $store, string $sku = 'REMOVAL-BASE'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Shopify Imported Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 100,
    ]));
}

/** Simulates the state right after a Shopify import: Color has Black/White/Clear, one variant each. */
function removalSeedThreeColors(Product $product): array
{
    return app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Black', 'White', 'Clear']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
        ['sku' => 'REMOVAL-BASE-CLR', 'price' => 100, 'options' => ['Color' => 'Clear']],
    ]);
}

it('allows a Shopify-imported product with option values to be edited without error', function (): void {
    [, $store] = removalWorkspace();
    $product = removalProduct($store);

    $result = removalSeedThreeColors($product);

    expect($result['variants'])->toHaveCount(3);

    // Re-saving the exact same state (a no-op edit) must not fail or drop anything.
    $again = removalSeedThreeColors($product->fresh());
    expect($again['variants'])->toHaveCount(3);
});

it('prevents a removed option value from appearing after reload', function (): void {
    [$owner, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    // User removes "Clear" and saves.
    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    // Reopen the product (fresh GET, exactly like leaving and coming back).
    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Products/Edit')
            ->where('product.options.0.values', ['Black', 'White']));
});

it('does not show variants that used the removed value as active after reload', function (): void {
    [$owner, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('product.variants', 2));

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(2);
});

it('archives (does not hard-delete) a removed option value that is referenced by an external listing', function (): void {
    [, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'removal-test.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'gid-1', 'sync_status' => 'synced',
    ]));

    $clearVariant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'REMOVAL-BASE-CLR')->firstOrFail());
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $clearVariant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'gid-var-clear', 'sync_status' => 'synced',
    ]));

    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    $clearValue = ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()->where('value', 'Clear')->first());

    expect($clearValue)->not->toBeNull()
        ->and($clearValue->is_active)->toBeFalse();
});

it('archives (does not hard-delete) a variant that is referenced by an external listing', function (): void {
    [, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'removal-variant.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'gid-2', 'sync_status' => 'synced',
    ]));

    $clearVariant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'REMOVAL-BASE-CLR')->firstOrFail());
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $clearVariant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'gid-var-clear-2', 'sync_status' => 'synced',
    ]));

    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    // Still physically present (soft-deleted), not visible in the active relation.
    $stillExists = ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()->find($clearVariant->id));
    $activeCount = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count());

    expect($stillExists)->not->toBeNull()
        ->and($stillExists->trashed())->toBeTrue()
        ->and($activeCount)->toBe(2)
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $clearVariant->id)->exists()))->toBeTrue();
});

it('generates variants using only active option values, ignoring removed ones', function (): void {
    [, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    $result = app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    expect($result['variants'])->toHaveCount(2);

    $result['variants']->each(function (ProductVariant $variant) {
        expect($variant->attributeValues->pluck('value')->all())->not->toContain('Clear');
    });
});

it('does not use archived option values to rebuild active options', function (): void {
    [$owner, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    // Re-open a second time (simulating multiple visits) without ever
    // resubmitting "Clear" — it must stay gone permanently, not just once.
    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")->assertOk();
    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertInertia(fn ($page) => $page->where('product.options.0.values', ['Black', 'White']));

    $attribute = ProductAttribute::withoutTenancy(fn () => ProductAttribute::query()->where('product_id', $product->id)->where('name', 'Color')->firstOrFail());
    expect($attribute->values()->active()->pluck('value')->sort()->values()->all())->toBe(['Black', 'White']);
});

it('does not let a legacy ProductVariant.attributes JSON value reintroduce a removed option value', function (): void {
    [$owner, $store] = removalWorkspace();
    $product = removalProduct($store);
    removalSeedThreeColors($product);

    app(ProductVariantWizardService::class)->sync($product->fresh(), [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'REMOVAL-BASE-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'REMOVAL-BASE-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    // Simulate stale legacy JSON on a kept variant — direct DB write,
    // bypassing syncAttributeValues(), the way old import data could have
    // left the JSON column out of sync with the canonical pivot.
    $blackVariant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'REMOVAL-BASE-BLK')->firstOrFail());
    $blackVariant->forceFill(['attributes' => ['Color' => 'Clear']])->save();

    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.options.0.values', ['Black', 'White']));

    expect($blackVariant->fresh()->attributeValues->pluck('value')->all())->toBe(['Black'])
        ->and($blackVariant->fresh()->attributeValues->pluck('value')->all())->not->toContain('Clear');
});
