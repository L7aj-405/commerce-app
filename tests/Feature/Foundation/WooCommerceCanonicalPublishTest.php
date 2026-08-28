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
use Illuminate\Support\Facades\Http;

/**
 * Phase S2 — the synchronous /publish endpoint now routes WooCommerce
 * through ProductChannelPublisher, the same canonical mapper-driven path
 * Shopify and /publish-queued already use. Options/variants always come
 * from ProductAttribute/ProductAttributeValue/ProductVariant, never a
 * variant's display name or the legacy `attributes` JSON column.
 */

/** @return array{0: User, 1: Store} */
function wcpWorkspace(string $name = 'Woo Canonical Publish Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function wcpWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('publishes a simple WooCommerce product using the canonical mapper, updating the existing listing (test: simple publish)', function (): void {
    [$owner, $store] = wcpWorkspace();
    $woo = wcpWooConnection($store, 'wcp1-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Canonical Simple', 'sku' => 'WCP-1', 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'wcp-1-remote', 'sync_status' => 'synced',
    ]));

    Http::fake(['wcp1-woo.example.com/wp-json/wc/v3/products/wcp-1-remote' => Http::response(['id' => 'wcp-1-remote'], 200)]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('succeeded');
    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/products/wcp-1-remote')
        && $r['type'] === 'simple'
        && ! array_key_exists('attributes', $r->data()));
});

it('publishes a variable WooCommerce product with canonical attributes and variations, creating missing ones (test: variable publish)', function (): void {
    [$owner, $store] = wcpWorkspace();
    $woo = wcpWooConnection($store, 'wcp2-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Canonical Variable', 'sku' => 'WCP-2', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => 'WCP-2-S', 'price' => 40, 'options' => ['Size' => 'S']],
        ['sku' => 'WCP-2-M', 'price' => 42, 'options' => ['Size' => 'M']],
    ]);

    Http::fake([
        'wcp2-woo.example.com/wp-json/wc/v3/products' => Http::response(['id' => 'wcp-2-remote'], 201),
        'wcp2-woo.example.com/wp-json/wc/v3/products/wcp-2-remote/variations' => Http::sequence()
            ->push(['id' => 'wcp-2-var-s'], 201)
            ->push(['id' => 'wcp-2-var-m'], 201),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    // Parent payload carries canonical attributes with variation:true — never
    // a top-level sku/price (those live on each variation instead).
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/wp-json/wc/v3/products')
        && ! str_contains($r->url(), 'variations')
        && $r['type'] === 'variable'
        && ($r['attributes'][0]['name'] ?? null) === 'Size'
        && ($r['attributes'][0]['variation'] ?? null) === true
        && $r['attributes'][0]['options'] === ['S', 'M']);

    Http::assertSentCount(3); // parent create + 2 variation creates

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2);
});

it('publishing a Shopify-imported product to WooCommerce does not duplicate the local Product (cross-channel)', function (): void {
    [$owner, $store] = wcpWorkspace();
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'wcp3-shop.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $woo = wcpWooConnection($store, 'wcp3-woo.example.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Cross Channel Simple', 'sku' => 'WCP-3', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'wcp-3-shopify-remote', 'sync_status' => 'synced',
    ]));

    Http::fake(['wcp3-woo.example.com/wp-json/wc/v3/products' => Http::response(['id' => 'wcp-3-woo-remote'], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2);
});

it('never uses a variant display name or the legacy attributes JSON column for canonical publish', function (): void {
    [$owner, $store] = wcpWorkspace();
    $woo = wcpWooConnection($store, 'wcp4-woo.example.com');

    // A variant with ONLY a legacy `attributes` JSON blob (no canonical
    // ProductAttribute/ProductAttributeValue) and a misleading display name.
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Legacy Attrs Only', 'sku' => 'WCP-4', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Totally Wrong Name', 'sku' => 'WCP-4-LEGACY', 'price' => 40, 'attributes' => ['Color' => 'Blue'],
    ]));

    Http::fake(['wcp4-woo.example.com/*' => Http::response(['id' => 'should-not-be-called'], 200)]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    // No canonical options exist, so readiness blocks before any HTTP call —
    // the legacy attributes column is never consulted to build a payload.
    expect($response->json('results.0.status'))->toBe('failed');
    Http::assertNothingSent();
});
