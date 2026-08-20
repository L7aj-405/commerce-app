<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\ProductSyncService;
use Illuminate\Support\Facades\Http;

/** @return array{0: User, 1: Store} */
function xchanWorkspace(string $name = 'Cross Channel Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function xchanShopify(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function xchanWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

/** Simulates a product already imported from Shopify (has a Shopify ProductChannelListing). */
function xchanImportFromShopify(Store $store, PlatformConnection $shopify, string $externalId, string $sku, string $name = 'Widget'): Product
{
    app(ProductSyncService::class)->saveProduct([
        'external_id' => $externalId, 'name' => $name, 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ], $store, 'shopify', $shopify);

    return Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', $sku)->firstOrFail());
}

function xchanImportFromWoo(Store $store, PlatformConnection $woo, string $externalId, string $sku, string $name = 'Gizmo'): Product
{
    app(ProductSyncService::class)->saveProduct([
        'external_id' => $externalId, 'name' => $name, 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ], $store, 'woocommerce', $woo);

    return Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', $sku)->firstOrFail());
}

it('gives a Shopify-imported product a Shopify ProductChannelListing', function (): void {
    [, $store] = xchanWorkspace();
    $shopify = xchanShopify($store, 'x1-shop.myshopify.com');
    $product = xchanImportFromShopify($store, $shopify, 'shop-101', 'X1-SKU');

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->first());

    expect($listing)->not->toBeNull()
        ->and($listing->external_product_id)->toBe('shop-101');
});

it('publishing a Shopify-imported product to WooCommerce with create_missing_listings=true creates the remote product and saves a WooCommerce listing on the same local product', function (): void {
    [$owner, $store] = xchanWorkspace();
    $shopify = xchanShopify($store, 'x2-shop.myshopify.com');
    $woo = xchanWoo($store, 'x2-woo.example.com');
    $product = xchanImportFromShopify($store, $shopify, 'shop-102', 'X2-SKU');

    Http::fake(['x2-woo.example.com/wp-json/wc/v3/products' => Http::response(['id' => 9001], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    $wooListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $woo->id)->first());

    expect($wooListing)->not->toBeNull()
        ->and($wooListing->external_product_id)->toBe('9001')
        ->and(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1);
});

it('running WooCommerce sync after publishing updates the same local product instead of creating a duplicate', function (): void {
    [$owner, $store] = xchanWorkspace();
    $shopify = xchanShopify($store, 'x3-shop.myshopify.com');
    $woo = xchanWoo($store, 'x3-woo.example.com');
    $product = xchanImportFromShopify($store, $shopify, 'shop-103', 'X3-SKU');

    Http::fake(['x3-woo.example.com/wp-json/wc/v3/products' => Http::response(['id' => 9002], 201)]);
    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id], 'create_missing_listings' => true,
    ])->assertOk();

    Http::fake(['x3-woo.example.com/wp-json/wc/v3/products*' => Http::response([
        ['id' => 9002, 'sku' => 'X3-SKU', 'name' => 'Widget', 'type' => 'simple', 'status' => 'publish', 'price' => '20', 'regular_price' => '20'],
    ], 200)]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'woocommerce');

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2);
});

it('publishing again to WooCommerce updates the existing remote product instead of creating a duplicate', function (): void {
    [$owner, $store] = xchanWorkspace();
    $woo = xchanWoo($store, 'x4-woo.example.com');
    $product = xchanImportFromWoo($store, $woo, 'woo-201', 'X4-SKU');

    Http::fake(['x4-woo.example.com/wp-json/wc/v3/products/woo-201' => Http::response(['id' => 'woo-201'], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/products/woo-201'));
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(1);
});

it('publishing a Shopify-imported product back to Shopify updates the existing remote product', function (): void {
    [$owner, $store] = xchanWorkspace();
    $shopify = xchanShopify($store, 'x5-shop.myshopify.com');
    $product = xchanImportFromShopify($store, $shopify, 'shop-105', 'X5-SKU');

    Http::fake(['x5-shop.myshopify.com/admin/api/*/products/shop-105.json' => Http::response(['product' => ['id' => 'shop-105']], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/products/shop-105.json'));
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(1);
});

it('publishing a WooCommerce-imported product to Shopify creates a Shopify listing on the same local product', function (): void {
    [$owner, $store] = xchanWorkspace();
    $woo = xchanWoo($store, 'x6-woo.example.com');
    $shopify = xchanShopify($store, 'x6-shop.myshopify.com');
    $product = xchanImportFromWoo($store, $woo, 'woo-206', 'X6-SKU');

    Http::fake(['x6-shop.myshopify.com/admin/api/*/products.json' => Http::response(['product' => ['id' => 8001]], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->value('external_product_id')))->toBe('8001');
});

it('matches an existing Shopify product by external_product_id before falling back to SKU', function (): void {
    [, $store] = xchanWorkspace();
    $shopify = xchanShopify($store, 'x7-shop.myshopify.com');
    $product = xchanImportFromShopify($store, $shopify, 'shop-107', 'X7-SKU', 'Original Name');

    // Re-pull the same external id with a DIFFERENT sku in the payload — if
    // matching fell back to SKU first this would create a second product
    // instead of updating the one already linked by external_product_id.
    app(ProductSyncService::class)->saveProduct([
        'external_id' => 'shop-107', 'name' => 'Renamed', 'sku' => 'X7-SKU-CHANGED', 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ], $store, 'shopify', $shopify);

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and($product->fresh()->name)->toBe('Renamed')
        ->and($product->fresh()->sku)->toBe('X7-SKU-CHANGED');
});

it('matches an existing WooCommerce product by external_product_id before falling back to SKU', function (): void {
    [, $store] = xchanWorkspace();
    $woo = xchanWoo($store, 'x8-woo.example.com');
    $product = xchanImportFromWoo($store, $woo, 'woo-208', 'X8-SKU', 'Original Gizmo');

    app(ProductSyncService::class)->saveProduct([
        'external_id' => 'woo-208', 'name' => 'Renamed Gizmo', 'sku' => 'X8-SKU-CHANGED', 'type' => 'simple', 'status' => 'active', 'price' => 33,
    ], $store, 'woocommerce', $woo);

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and($product->fresh()->name)->toBe('Renamed Gizmo');
});

it('SKU fallback attaches an external product to an existing local product only when exactly one product in the same store matches', function (): void {
    [, $store] = xchanWorkspace();
    $woo = xchanWoo($store, 'x9-woo.example.com');

    // A pre-existing manually-created local product with this SKU but no
    // WooCommerce listing yet (e.g. created directly in the SaaS wizard).
    $existing = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Manual Product', 'sku' => 'X9-SKU', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));

    app(ProductSyncService::class)->saveProduct([
        'external_id' => 'woo-209', 'name' => 'Pulled From Woo', 'sku' => 'X9-SKU', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ], $store, 'woocommerce', $woo);

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1);

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $existing->id)->where('platform_connection_id', $woo->id)->first());

    expect($listing)->not->toBeNull()
        ->and($listing->external_product_id)->toBe('woo-209');
});

it('does not let SKU fallback attach an external product across stores', function (): void {
    [, $storeA] = xchanWorkspace('Cross Channel Store A');
    [, $storeB] = xchanWorkspace('Cross Channel Store B');
    $wooB = xchanWoo($storeB, 'x10-woo.example.com');

    // Same SKU, but the existing product belongs to a DIFFERENT store.
    Product::withoutTenancy(fn () => Product::create([
        'store_id' => $storeA->id, 'name' => 'Store A Product', 'sku' => 'X10-SKU', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));

    app(ProductSyncService::class)->saveProduct([
        'external_id' => 'woo-210', 'name' => 'Pulled Into Store B', 'sku' => 'X10-SKU', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ], $storeB, 'woocommerce', $wooB);

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeA->id)->count()))->toBe(1)
        ->and(Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeB->id)->count()))->toBe(1);

    $storeBProduct = Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeB->id)->firstOrFail());
    expect($storeBProduct->name)->toBe('Pulled Into Store B');
});

it('does not trigger SKU fallback merge when the remote SKU is blank', function (): void {
    [, $store] = xchanWorkspace();
    $woo = xchanWoo($store, 'x11-woo.example.com');

    Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'No Sku Target', 'sku' => 'X11-EXISTING-SKU', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));

    app(ProductSyncService::class)->saveProduct([
        'external_id' => 'woo-211', 'name' => 'Blank Sku Import', 'sku' => '', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ], $store, 'woocommerce', $woo);

    // A blank SKU must always create its own product — never guess-merge.
    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(2);
});

it('does not unsafely merge when more than one candidate shares the same SKU', function (): void {
    [, $store] = xchanWorkspace();
    $woo = xchanWoo($store, 'x12-woo.example.com');

    // products.sku is a global-unique DB column, so two real rows can never
    // literally share one SKU — this exercises the decision function
    // directly with a simulated 2-candidate set, the same shape the real
    // query would produce if that constraint were ever relaxed.
    $candidateA = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Candidate A', 'sku' => 'X12-SKU-A', 'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]));
    $candidateB = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Candidate B', 'sku' => 'X12-SKU-B', 'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]));

    $resolved = app(ProductSyncService::class)->resolveSkuFallbackCandidate(collect([$candidateA, $candidateB]), $woo);

    expect($resolved)->toBeNull();
});
