<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use Illuminate\Validation\ValidationException;

/** @return array{0: User, 1: Store, 2: \App\Models\Organization} */
function canonicalizationWorkspace(string $name = 'Canonicalization Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

function canonicalizationProduct(Store $store, string $sku = 'CV1-BASE'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Wizard Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 100,
    ]));
}

it('upserts product options and values as ProductAttribute/ProductAttributeValue rows', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
    ], [
        ['sku' => 'CV1-X', 'price' => 100, 'options' => ['Size' => 'X']],
        ['sku' => 'CV1-XL', 'price' => 100, 'options' => ['Size' => 'XL']],
    ]);

    $attribute = ProductAttribute::withoutTenancy(fn () => ProductAttribute::query()->where('product_id', $product->id)->where('name', 'Size')->firstOrFail());

    expect($attribute->values()->pluck('value')->all())->toBe(['X', 'XL']);
});

it('shows saved options and values after reopening (re-querying) the product', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'CV1-BLK', 'price' => 100, 'options' => ['Color' => 'Black']],
        ['sku' => 'CV1-WHT', 'price' => 100, 'options' => ['Color' => 'White']],
    ]);

    $reloaded = Product::withoutTenancy(fn () => Product::with('attributes.values')->find($product->id));

    expect($reloaded->attributes)->toHaveCount(1)
        ->and($reloaded->attributes->first()->values->pluck('value')->all())->toBe(['Black', 'White']);
});

it('generates a variant for every Cartesian combination submitted', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    $result = app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'CV1-X-BLK', 'price' => 100, 'options' => ['Size' => 'X', 'Color' => 'Black']],
        ['sku' => 'CV1-X-WHT', 'price' => 100, 'options' => ['Size' => 'X', 'Color' => 'White']],
        ['sku' => 'CV1-XL-BLK', 'price' => 100, 'options' => ['Size' => 'XL', 'Color' => 'Black']],
        ['sku' => 'CV1-XL-WHT', 'price' => 100, 'options' => ['Size' => 'XL', 'Color' => 'White']],
    ]);

    expect($result['variants'])->toHaveCount(4)
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(4);

    $result['variants']->each(function (ProductVariant $variant) {
        expect($variant->attributeValues)->toHaveCount(2);
    });
});

it('preserves an existing variant id, sku, price, channel listing and inventory link when regenerating the same combination', function (): void {
    [, $store, $organization] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);
    $service = app(ProductVariantWizardService::class);

    $service->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'CV1-KEEP', 'price' => 100, 'options' => ['Size' => 'X']],
    ]);

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $originalId = $variant->id;

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'status' => 'active',
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'gid://1', 'sync_status' => 'synced',
    ]));
    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'gid://var/1', 'sync_status' => 'synced',
    ]));

    $item = InventoryItem::create(['organization_id' => $organization->id, 'sku' => 'CV1-KEEP', 'name' => 'Inv item', 'unit' => 'unit']);
    VariantInventoryLink::create(['organization_id' => $organization->id, 'inventory_item_id' => $item->id, 'product_variant_id' => $variant->id]);

    // Regenerate the exact same combination with a changed price/sku.
    $result = $service->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['id' => $variant->id, 'sku' => 'CV1-KEEP-UPDATED', 'price' => 150, 'options' => ['Size' => 'X']],
    ]);

    $preserved = $result['variants']->first();

    expect($preserved->id)->toBe($originalId)
        ->and($preserved->sku)->toBe('CV1-KEEP-UPDATED')
        ->and((float) $preserved->price)->toBe(150.0)
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->find($variantListing->id)))->not->toBeNull()
        ->and(VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $originalId)->exists()))->toBeTrue();
});

it('does not delete a variant with an external channel listing when its combination is removed, and returns a warning', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);
    $service = app(ProductVariantWizardService::class);

    $service->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
    ], [
        ['sku' => 'CV1-X', 'price' => 100, 'options' => ['Size' => 'X']],
        ['sku' => 'CV1-XL', 'price' => 100, 'options' => ['Size' => 'XL']],
    ]);

    $xlVariant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'CV1-XL')->firstOrFail());

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'status' => 'active',
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'gid://1', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $xlVariant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'gid://var/xl', 'sync_status' => 'synced',
    ]));

    // Resubmit without the XL combination — it should be kept, not destroyed.
    $result = $service->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'CV1-X', 'price' => 100, 'options' => ['Size' => 'X']],
    ]);

    expect($result['warnings'])->not->toBeEmpty()
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($xlVariant->id)))->not->toBeNull();
});

it('rejects a duplicate option name', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
        ['name' => 'size', 'values' => ['XL']],
    ], []);
})->throws(ValidationException::class);

it('rejects a duplicate option value under the same option', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'x']],
    ], []);
})->throws(ValidationException::class);

it('rejects duplicate variant combinations in the same submission', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'CV1-A', 'price' => 100, 'options' => ['Size' => 'X']],
        ['sku' => 'CV1-B', 'price' => 100, 'options' => ['Size' => 'X']],
    ]);
})->throws(ValidationException::class);

it('rejects a variant missing a value for a defined option', function (): void {
    [, $store] = canonicalizationWorkspace();
    $product = canonicalizationProduct($store);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
        ['name' => 'Color', 'values' => ['Black']],
    ], [
        ['sku' => 'CV1-A', 'price' => 100, 'options' => ['Size' => 'X']],
    ]);
})->throws(ValidationException::class);
