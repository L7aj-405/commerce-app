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
use App\Services\Sync\ProductPushService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shopify quantity is never set via a product/variant update payload — it
 * lives on InventoryLevel, keyed by inventory_item_id + location_id
 * (POST /inventory_levels/set.json). These tests exercise the resolution
 * chain (saved metadata -> Shopify fetch -> persist) at the
 * ProductPushService/ShopifyConnector level, independent of the stock
 * adjustment HTTP endpoint (see ShopifyStockAdjustmentPushTest for that).
 */

/** @return array{0: User, 1: Store} */
function sisWorkspace(string $name = 'Shopify Inventory Sync Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function sisShopifyConnection(Store $store, string $domain, array $metadata = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test_secret_token', 'metadata' => $metadata,
    ]));
}

function sisSimpleProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Inventory Widget', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));
}

it('sets Shopify inventory using the saved default_inventory_item_id, without fetching the product (test 1)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis1-shop.myshopify.com', ['location_id' => '900001']);
    $product = sisSimpleProduct($store, 'SIS-1');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-1-remote',
        'sync_status' => 'synced', 'metadata' => ['default_variant_id' => '10001', 'default_inventory_item_id' => '20001'],
    ]));

    Http::fake(['sis1-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 8]], 200)]);

    $results = app(ProductPushService::class)->pushStock($product, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/inventory_levels/set.json')
        && $r['inventory_item_id'] === '20001'
        && $r['location_id'] === '900001');
});

it('fetches the default variant inventory_item_id from Shopify when missing, and stores it (test 2)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis2-shop.myshopify.com', ['location_id' => '900002']);
    $product = sisSimpleProduct($store, 'SIS-2');
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-2-remote', 'sync_status' => 'synced',
        // No default_inventory_item_id saved yet.
    ]));

    Http::fake([
        'sis2-shop.myshopify.com/admin/api/*/products/sis-2-remote.json' => Http::response(['product' => [
            'id' => 'sis-2-remote', 'variants' => [['id' => 10002, 'inventory_item_id' => 20002]],
        ]], 200),
        'sis2-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 5]], 200),
    ]);

    $results = app(ProductPushService::class)->pushStock($product, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/inventory_levels/set.json') && $r['inventory_item_id'] === '20002');

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id))->metadata['default_inventory_item_id'])->toBe('20002');
});

it('uses ProductVariantChannelListing.external_inventory_item_id for a variant stock adjustment (test 3)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis3-shop.myshopify.com', ['location_id' => '900003']);

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Inventory Widget', 'sku' => 'SIS-3', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'SIS-3-S', 'price' => 40, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-3-remote', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => '30003', 'external_inventory_item_id' => '40003', 'sync_status' => 'synced',
    ]));

    Http::fake(['sis3-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 3]], 200)]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSentCount(1); // no /variants/{id}.json fetch — the saved id was used directly.
    Http::assertSent(fn ($r) => $r['inventory_item_id'] === '40003');
});

it('fetches a variant inventory_item_id from Shopify when missing, and stores it (test 4)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis4-shop.myshopify.com', ['location_id' => '900004']);

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Fetch Widget', 'sku' => 'SIS-4', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['M']],
    ], [
        ['sku' => 'SIS-4-M', 'price' => 40, 'options' => ['Size' => 'M']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-4-remote', 'sync_status' => 'synced',
    ]));
    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => '30004', 'sync_status' => 'synced',
        // No external_inventory_item_id saved yet.
    ]));

    Http::fake([
        'sis4-shop.myshopify.com/admin/api/*/variants/30004.json' => Http::response(['variant' => ['id' => 30004, 'inventory_item_id' => 40004]], 200),
        'sis4-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 6]], 200),
    ]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['inventory_item_id'] === '40004');

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->find($variantListing->id))->external_inventory_item_id)->toBe('40004');
});

it('resolves the Shopify location id and stores it in PlatformConnection metadata (test 5)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis5-shop.myshopify.com'); // no metadata yet
    $product = sisSimpleProduct($store, 'SIS-5');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-5-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '20005'],
    ]));

    Http::fake([
        'sis5-shop.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => [
            ['id' => 900005, 'name' => 'Main', 'active' => true],
        ]], 200),
        'sis5-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 4]], 200),
    ]);

    app(ProductPushService::class)->pushStock($product, 'shopify');

    expect(PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($shopify->id))->metadata['location_id'])->toBe('900005');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['location_id'] === '900005');
});

it('returns a clear failure when no Shopify location can be resolved (test 6)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis6-shop.myshopify.com');
    $product = sisSimpleProduct($store, 'SIS-6');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-6-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '20006'],
    ]));

    Http::fake(['sis6-shop.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200)]);

    $results = app(ProductPushService::class)->pushStock($product, 'shopify');

    expect($results[0]['success'] ?? null)->toBeFalse()
        ->and($results[0]['message'] ?? null)->toBe('No Shopify location found for inventory sync.');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json'));
});

it('never sends inventory_quantity or old_inventory_quantity in a product/variant publish payload (test 8)', function (): void {
    [$owner, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis8-shop.myshopify.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'No Quantity Widget', 'sku' => 'SIS-8', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => 'SIS-8-S', 'price' => 40, 'options' => ['Size' => 'S']],
        ['sku' => 'SIS-8-M', 'price' => 42, 'options' => ['Size' => 'M']],
    ]);

    Http::fake(['sis8-shop.myshopify.com/admin/api/*/products.json' => Http::response([
        'product' => ['id' => 'sis-8-remote', 'variants' => [
            ['id' => 50001, 'option1' => 'S', 'sku' => 'SIS-8-S', 'inventory_item_id' => 60001],
            ['id' => 50002, 'option1' => 'M', 'sku' => 'SIS-8-M', 'inventory_item_id' => 60002],
        ]],
    ], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    Http::assertSent(function ($r) {
        $body = $r['product'];

        if (array_key_exists('inventory_quantity', $body) || array_key_exists('old_inventory_quantity', $body)) {
            return false;
        }

        foreach ($body['variants'] ?? [] as $v) {
            if (array_key_exists('inventory_quantity', $v) || array_key_exists('old_inventory_quantity', $v)) {
                return false;
            }
        }

        return true;
    });

    // Publish still captured the inventory_item_id it got for free from
    // the response, for a later stock adjustment to use without a fetch.
    $variantListings = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->get());
    expect($variantListings->pluck('external_inventory_item_id')->sort()->values()->all())->toBe(['60001', '60002']);
});

it('never logs the Shopify access token when an inventory push fails (test 10)', function (): void {
    [, $store] = sisWorkspace();
    $shopify = sisShopifyConnection($store, 'sis10-shop.myshopify.com', ['location_id' => '900010']);
    $product = sisSimpleProduct($store, 'SIS-10');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sis-10-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '20010'],
    ]));

    Http::fake(['sis10-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'Server error'], 500)]);

    Log::spy();

    $results = app(ProductPushService::class)->pushStock($product, 'shopify');

    expect($results[0]['success'] ?? null)->toBeFalse();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
        $haystack = $message . ' ' . json_encode($context);

        return ! str_contains($haystack, 'shpat_test_secret_token');
    });
});
