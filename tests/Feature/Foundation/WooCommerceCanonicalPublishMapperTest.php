<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Services\Publishing\ProductChannelPublisher;
use App\Services\Publishing\WooCommerce\WooCommerceProductPayloadMapper;
use Illuminate\Support\Facades\Http;

/** @return array{0: User, 1: Store} */
function wooMapperWorkspace(string $name = 'Woo Mapper Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function wooMapperProduct(Store $store, string $sku, string $type = 'simple'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Woo Mapper Product', 'sku' => $sku, 'type' => $type, 'status' => 'active', 'price' => 199,
    ]));
}

function wooMapperConnection(Store $store, string $domain = 'woo-mapper.example.com'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('maps a simple canonical product to a WooCommerce simple payload', function (): void {
    [, $store] = wooMapperWorkspace();
    $product = wooMapperProduct($store, 'WOO-SIMPLE-1');

    $map = app(WooCommerceProductPayloadMapper::class)->map($product);

    expect($map['ready'])->toBeTrue()
        ->and($map['payload']['type'])->toBe('simple')
        ->and($map['payload']['sku'])->toBe('WOO-SIMPLE-1')
        ->and($map['payload']['regular_price'])->toBe('199')
        ->and($map['variations'])->toBe([]);
});

it('maps canonical options to WooCommerce variable attributes with visible/variation true', function (): void {
    [, $store] = wooMapperWorkspace();
    $product = wooMapperProduct($store, 'WOO-VAR-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => 'WOO-VAR-1-X-BLK', 'price' => 199, 'options' => ['Size' => 'X', 'Color' => 'Black']],
    ]);

    $map = app(WooCommerceProductPayloadMapper::class)->map($product->fresh());

    expect($map['ready'])->toBeTrue()
        ->and($map['payload']['type'])->toBe('variable')
        ->and($map['payload']['attributes'])->toBe([
            ['name' => 'Size', 'visible' => true, 'variation' => true, 'options' => ['X', 'XL']],
            ['name' => 'Color', 'visible' => true, 'variation' => true, 'options' => ['Black', 'White']],
        ]);
});

it('maps canonical variants to WooCommerce variation attribute payloads', function (): void {
    [, $store] = wooMapperWorkspace();
    $product = wooMapperProduct($store, 'WOO-VARIATION-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'WOO-VARIATION-1-X', 'price' => 199, 'options' => ['Size' => 'X']],
    ]);

    $map = app(WooCommerceProductPayloadMapper::class)->map($product->fresh());
    $variation = $map['variations'][0]['payload'];

    expect($variation['sku'])->toBe('WOO-VARIATION-1-X')
        ->and($variation['regular_price'])->toBe('199')
        ->and($variation['attributes'])->toBe([['name' => 'Size', 'option' => 'X']]);
});

it('uses the existing remote product id and remote variation id, and does not duplicate listings', function (): void {
    [$owner, $store] = wooMapperWorkspace();
    $connection = wooMapperConnection($store, 'woo-existing.example.com');
    $product = wooMapperProduct($store, 'WOO-EXIST-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'WOO-EXIST-1-X', 'price' => 199, 'options' => ['Size' => 'X']],
    ]);
    $product->refresh();
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'woo-parent-1', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'woo-variation-1', 'sync_status' => 'synced',
    ]));

    Http::fake([
        'woo-existing.example.com/wp-json/wc/v3/products/woo-parent-1' => Http::response(['id' => 'woo-parent-1'], 200),
        'woo-existing.example.com/wp-json/wc/v3/products/woo-parent-1/variations/woo-variation-1' => Http::response(['id' => 'woo-variation-1'], 200),
    ]);

    $result = app(ProductChannelPublisher::class)->publish($product, $connection, false);

    expect($result['status'])->toBe('succeeded');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/products/woo-parent-1') && ! str_contains($r->url(), 'variations'));
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/products/woo-parent-1/variations/woo-variation-1'));
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(1)
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $variant->id)->count()))->toBe(1);
});

it('catches a single variation timeout and marks only that variation failed, without failing the whole publish', function (): void {
    [$owner, $store] = wooMapperWorkspace();
    $connection = wooMapperConnection($store, 'woo-timeout.example.com');
    $product = wooMapperProduct($store, 'WOO-TIMEOUT-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
    ], [
        ['sku' => 'WOO-TIMEOUT-1-X', 'price' => 199, 'options' => ['Size' => 'X']],
        ['sku' => 'WOO-TIMEOUT-1-XL', 'price' => 199, 'options' => ['Size' => 'XL']],
    ]);
    $product->refresh();

    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'woo-timeout-parent', 'sync_status' => 'synced',
    ]));

    Http::fake([
        'woo-timeout.example.com/wp-json/wc/v3/products/woo-timeout-parent' => Http::response(['id' => 'woo-timeout-parent'], 200),
        'woo-timeout.example.com/wp-json/wc/v3/products/woo-timeout-parent/variations' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        },
    ]);

    // create_missing_listings = true so both variations are actually attempted
    // (no existing ProductVariantChannelListing rows yet — both go through
    // createVariationPayload, and BOTH hit the same faked timeout).
    $result = app(ProductChannelPublisher::class)->publish($product, $connection, true);

    // The parent product publish itself still succeeded (it's a separate
    // HTTP call) — only the variation-level pushes failed, and each one was
    // isolated instead of aborting the loop or crashing the whole publish.
    expect($result['status'])->toBe('succeeded')
        ->and($result['variant_failures'])->toHaveCount(2)
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->count()))->toBe(0);
});
