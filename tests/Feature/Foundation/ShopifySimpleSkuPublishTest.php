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

/**
 * Shopify SKU belongs to the variant, never the product parent — even a
 * simple Shopify product has a default variant. Publishing a SaaS simple
 * product must update that default variant's SKU explicitly, by id, not
 * rely on an id-less variant embedded in the parent product update.
 */

/** @return array{0: User, 1: Store} */
function ssspWorkspace(string $name = 'Simple Sku Publish Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ssspShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function ssspSimpleProduct(Store $store, string $sku, float $price = 40): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Sku Widget', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => $price,
    ]));
}

it('updates the Shopify product title on publish (test 1)', function (): void {
    [$owner, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp1-shop.myshopify.com');
    $product = ssspSimpleProduct($store, 'SSSP-1');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sssp-1-remote', 'sync_status' => 'synced',
    ]));

    Http::fake([
        'sssp1-shop.myshopify.com/admin/api/*/products/sssp-1-remote.json' => Http::response(['product' => ['id' => 'sssp-1-remote', 'variants' => [['id' => 11001, 'sku' => 'SSSP-1']]]], 200),
        'sssp1-shop.myshopify.com/admin/api/*/variants/11001.json' => Http::response(['variant' => ['id' => 11001, 'sku' => 'SSSP-1']], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/products/sssp-1-remote.json')
        && $r['product']['title'] === 'Sku Widget');
});

it('updates the Shopify default variant SKU on publish, not just the parent title (test 2)', function (): void {
    [$owner, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp2-shop.myshopify.com');
    $product = ssspSimpleProduct($store, 'SSSP-2-NEW', 44);
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sssp-2-remote', 'sync_status' => 'synced',
    ]));

    Http::fake([
        'sssp2-shop.myshopify.com/admin/api/*/products/sssp-2-remote.json' => Http::response(['product' => ['id' => 'sssp-2-remote', 'variants' => [['id' => 11002, 'sku' => 'SSSP-2-OLD']]]], 200),
        'sssp2-shop.myshopify.com/admin/api/*/variants/11002.json' => Http::response(['variant' => ['id' => 11002, 'sku' => 'SSSP-2-NEW']], 200),
    ]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('succeeded');

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/variants/11002.json')
        && $r['variant']['sku'] === 'SSSP-2-NEW'
        && $r['variant']['price'] === '44.00');
});

it('uses the saved default variant id from ProductChannelListing metadata when present (test 3)', function (): void {
    [$owner, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp3-shop.myshopify.com');
    $product = ssspSimpleProduct($store, 'SSSP-3', 40);
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sssp-3-remote',
        'sync_status' => 'synced', 'metadata' => ['default_variant_id' => '55555'],
    ]));

    Http::fake([
        // The parent update response deliberately carries a DIFFERENT
        // variant id — if the saved metadata id were ignored, the code
        // would wrongly target this trap id instead.
        'sssp3-shop.myshopify.com/admin/api/*/products/sssp-3-remote.json' => Http::response(['product' => ['id' => 'sssp-3-remote', 'variants' => [['id' => 99999, 'sku' => 'TRAP']]]], 200),
        'sssp3-shop.myshopify.com/admin/api/*/variants/55555.json' => Http::response(['variant' => ['id' => 55555, 'sku' => 'SSSP-3']], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/variants/55555.json'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/variants/99999.json'));
});

it('falls back to the parent response variants[0].id when no default variant id is saved yet (test 4)', function (): void {
    [$owner, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp4-shop.myshopify.com');
    $product = ssspSimpleProduct($store, 'SSSP-4', 40);
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sssp-4-remote', 'sync_status' => 'synced',
        // No metadata['default_variant_id'] yet.
    ]));

    Http::fake([
        'sssp4-shop.myshopify.com/admin/api/*/products/sssp-4-remote.json' => Http::response(['product' => ['id' => 'sssp-4-remote', 'variants' => [['id' => 22002, 'sku' => 'SSSP-4-OLD']]]], 200),
        'sssp4-shop.myshopify.com/admin/api/*/variants/22002.json' => Http::response(['variant' => ['id' => 22002, 'sku' => 'SSSP-4']], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    // Only 2 requests total: the parent PUT (whose response already carried
    // variants[0].id) and the variant PUT — no extra GET fetch needed.
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/variants/22002.json'));

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->value('metadata')))->toBe(['default_variant_id' => '22002']);
});

it('sends the default variant sku when creating a brand new Shopify product (test 5)', function (): void {
    [$owner, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp5-shop.myshopify.com');
    $product = ssspSimpleProduct($store, 'SSSP-5', 40);

    Http::fake(['sssp5-shop.myshopify.com/admin/api/*/products.json' => Http::response([
        'product' => ['id' => 'sssp-5-remote', 'variants' => [['id' => 33003, 'sku' => 'SSSP-5']]],
    ], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/products.json')
        && $r['product']['variants'][0]['sku'] === 'SSSP-5');

    // Create is a single round trip — no separate variant PUT needed since
    // Shopify already applied the sku when creating the default variant.
    Http::assertSentCount(1);

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->value('metadata')))->toBe(['default_variant_id' => '33003']);
});

it('returns a failed result (not full success) when the title update succeeds but the SKU update fails (test 7)', function (): void {
    [$owner, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp7-shop.myshopify.com');
    $product = ssspSimpleProduct($store, 'SSSP-7', 40);
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sssp-7-remote',
        'sync_status' => 'synced', 'metadata' => ['default_variant_id' => '77007'],
    ]));

    Http::fake([
        'sssp7-shop.myshopify.com/admin/api/*/products/sssp-7-remote.json' => Http::response(['product' => ['id' => 'sssp-7-remote']], 200),
        'sssp7-shop.myshopify.com/admin/api/*/variants/77007.json' => Http::response(['errors' => 'Something went wrong'], 500),
    ]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('failed')
        ->and($response->json('results.0.message'))->toContain('Product updated but Shopify default variant SKU update failed.');

    // The parent title update DID succeed — the listing must still be kept,
    // never rolled back, so a later retry updates rather than duplicates.
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->count()))->toBe(1);
});

it('imports the SKU from the Shopify default variant into product.sku on sync, not from the product parent (test 8)', function (): void {
    [, $store] = ssspWorkspace();
    $shopify = ssspShopifyConnection($store, 'sssp8-shop.myshopify.com');

    Http::fake(['sssp8-shop.myshopify.com/admin/api/*/products.json*' => Http::response([
        'products' => [[
            'id' => 'sssp-8-remote',
            'title' => 'Imported Widget',
            'body_html' => '',
            'status' => 'active',
            // Shopify's product object itself has no top-level sku — only
            // its variants do.
            'variants' => [[
                'id' => 44004,
                'sku' => 'IMPORTED-SKU-8',
                'price' => '19.00',
            ]],
        ]],
    ], 200)]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'shopify');

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'IMPORTED-SKU-8')->first());

    expect($product)->not->toBeNull()
        ->and($product->type)->toBe('simple');
});
