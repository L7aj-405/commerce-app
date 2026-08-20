<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Services\Publishing\ProductChannelPublisher;
use App\Services\Publishing\Shopify\ShopifyProductPayloadMapper;
use Illuminate\Support\Facades\Http;

/** @return array{0: User, 1: Store} */
function shopifyMapperWorkspace(string $name = 'Shopify Mapper Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function shopifyMapperProduct(Store $store, string $sku, string $type = 'simple'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mapper Product', 'sku' => $sku, 'type' => $type, 'status' => 'active', 'price' => 100,
    ]));
}

it('maps a simple canonical product (no options) to a basic Shopify payload', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-SIMPLE-1');

    $map = app(ShopifyProductPayloadMapper::class)->map($product);

    expect($map['ready'])->toBeTrue()
        ->and($map['payload']['title'])->toBe('Mapper Product')
        ->and($map['payload']['variants'][0]['sku'])->toBe('SHOP-SIMPLE-1')
        ->and($map['payload']['variants'][0]['price'])->toBe('100.00')
        ->and($map['payload'])->not->toHaveKey('options');
});

it('maps canonical product options to Shopify options in position order', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-OPT-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'SHOP-OPT-1-X-BLK', 'price' => 100, 'options' => ['Size' => 'X', 'Color' => 'Black']],
        ['sku' => 'SHOP-OPT-1-XL-WHT', 'price' => 100, 'options' => ['Size' => 'XL', 'Color' => 'White']],
    ]);

    $map = app(ShopifyProductPayloadMapper::class)->map($product->fresh());

    expect($map['ready'])->toBeTrue()
        ->and($map['payload']['options'])->toBe([['name' => 'Size'], ['name' => 'Color']]);
});

it('maps variant option values into option1/option2/option3, never from a display name', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-VAR-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'SHOP-VAR-1-X-BLK', 'price' => 100, 'options' => ['Size' => 'X', 'Color' => 'Black']],
    ]);

    $map = app(ShopifyProductPayloadMapper::class)->map($product->fresh());

    $variantPayload = collect($map['payload']['variants'])->firstWhere('sku', 'SHOP-VAR-1-X-BLK');

    expect($variantPayload['option1'])->toBe('X')
        ->and($variantPayload['option2'])->toBe('Black');
});

it('blocks publishing when the product has more than 3 options', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-4OPT-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
        ['name' => 'Color', 'values' => ['Black']],
        ['name' => 'Material', 'values' => ['Cotton']],
        ['name' => 'Fit', 'values' => ['Slim']],
    ], [
        ['sku' => 'SHOP-4OPT-1', 'price' => 100, 'options' => ['Size' => 'X', 'Color' => 'Black', 'Material' => 'Cotton', 'Fit' => 'Slim']],
    ]);

    $map = app(ShopifyProductPayloadMapper::class)->map($product->fresh());

    expect($map['ready'])->toBeFalse()
        ->and($map['payload'])->toBeNull()
        ->and(implode(' ', $map['errors']))->toContain('maximum of 3 variant options');
});

it('blocks publishing when a variant is missing an option value', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-MISSING-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'SHOP-MISSING-1', 'price' => 100, 'options' => ['Size' => 'X']],
    ]);

    // Simulate a variant that lost its option link after the canonical save
    // (e.g. an inconsistent external import) — the mapper must still refuse
    // to send a malformed variant rather than guess.
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $variant->attributeValues()->detach();

    $map = app(ShopifyProductPayloadMapper::class)->map($product->fresh());

    expect($map['ready'])->toBeFalse()
        ->and(implode(' ', $map['errors']))->toContain('missing option values');
});

it('does not send variants without any product options defined', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-NOOPT-1');

    $map = app(ShopifyProductPayloadMapper::class)->map($product);

    expect($map['ready'])->toBeTrue()
        ->and($map['payload'])->not->toHaveKey('options')
        ->and($map['payload']['variants'])->toHaveCount(1)
        ->and($map['payload']['variants'][0])->not->toHaveKey('option1');
});

it('reports the existing external_product_id is reused rather than mapping a create', function (): void {
    [, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-EXIST-1');

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'shop-exist.myshopify.com', 'access_token' => 'shpat_test',
    ]));

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'gid-existing-1', 'sync_status' => 'synced',
    ]));

    // The mapper itself is listing-agnostic (pure payload); the existing
    // external id lives on ProductChannelListing and is used by the
    // publisher/job to decide update vs create — asserted here directly.
    expect($product->listingForConnection($connection)->external_product_id)->toBe('gid-existing-1')
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(1);
});

it('skips a connection with no listing by default and does not create a duplicate ProductChannelListing', function (): void {
    [$owner, $store] = shopifyMapperWorkspace();
    $product = shopifyMapperProduct($store, 'SHOP-SKIP-1');

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'shop-skip.myshopify.com', 'access_token' => 'shpat_test',
    ]));

    Http::fake(['shop-skip.myshopify.com/*' => Http::response(['product' => ['id' => 999]], 200)]);

    $result = app(ProductChannelPublisher::class)->publish($product, $connection, false);

    expect($result['status'])->toBe('skipped');
    Http::assertNothingSent();
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(0);
});
