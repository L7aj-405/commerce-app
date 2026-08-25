<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

function ptWorkspace(string $name = 'Publish Target Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ptWooConnection(Store $store, string $domain = 'pt-woo.example.com'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'woocommerce',
        'status' => 'active',
        'api_url' => "https://{$domain}",
        'consumer_key' => 'ck_test',
        'consumer_secret' => 'cs_test',
    ]));
}

function ptShopifyConnection(Store $store, string $domain = 'pt-shopify.myshopify.com'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_token',
        'status' => 'active',
        'shop_domain' => $domain,
        'access_token' => 'shpat_test',
    ]));
}

function ptProduct(Store $store, string $sku = 'PT-SKU-1', string $type = 'simple'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Publish Target Product', 'sku' => $sku, 'type' => $type, 'status' => 'active', 'price' => 30,
    ]));
}

function ptListing(Product $product, PlatformConnection $connection, string $externalId): ProductChannelListing
{
    return ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => $externalId, 'sync_status' => 'synced',
    ]));
}

it('requires at least one connection_id to publish a single product', function (): void {
    [$owner, $store] = ptWorkspace();
    $product = ptProduct($store);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [],
    ])->assertStatus(422);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [])
        ->assertStatus(422);
});

it('publishing to WooCommerce only does not call the Shopify API', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $shopify = ptShopifyConnection($store);
    $product = ptProduct($store, 'PT-BOTH-1');
    ptListing($product, $woo, 'woo-1');
    ptListing($product, $shopify, 'shop-1');

    Http::fake([
        'pt-woo.example.com/*' => Http::response(['id' => 'woo-1'], 200),
        'pt-shopify.myshopify.com/*' => Http::response(['id' => 'shop-1'], 200),
    ]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('succeeded')
        ->and($response->json('results.0.platform'))->toBe('woocommerce');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'pt-woo.example.com'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pt-shopify.myshopify.com'));
});

it('publishing to Shopify only does not call the WooCommerce API', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $shopify = ptShopifyConnection($store);
    $product = ptProduct($store, 'PT-BOTH-2');
    ptListing($product, $woo, 'woo-2');
    ptListing($product, $shopify, 'shop-2');

    Http::fake([
        'pt-woo.example.com/*' => Http::response(['id' => 'woo-2'], 200),
        'pt-shopify.myshopify.com/*' => Http::response(['id' => 'shop-2'], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'pt-shopify.myshopify.com'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pt-woo.example.com'));
});

it('publishes to both platforms only when both connection ids are explicitly selected', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $shopify = ptShopifyConnection($store);
    $product = ptProduct($store, 'PT-BOTH-3');
    ptListing($product, $woo, 'woo-3');
    ptListing($product, $shopify, 'shop-3');

    Http::fake([
        'pt-woo.example.com/*' => Http::response(['id' => 'woo-3'], 200),
        // Shopify SKU lives on the default variant, not the parent — the
        // response must carry a variants array so the publisher can resolve
        // and confirm the default variant, same as a real Shopify response.
        'pt-shopify.myshopify.com/*' => Http::response(['product' => ['id' => 'shop-3', 'variants' => [['id' => 900003, 'sku' => 'PT-BOTH-3']]]], 200),
    ]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id, $shopify->id],
    ])->assertOk();

    expect(collect($response->json('results'))->where('status', 'succeeded'))->toHaveCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'pt-woo.example.com'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'pt-shopify.myshopify.com'));
});

it('updates only WooCommerce after a SaaS edit when WooCommerce alone is selected', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $shopify = ptShopifyConnection($store);
    $product = ptProduct($store, 'PT-EDIT-1');
    ptListing($product, $woo, 'woo-4');
    ptListing($product, $shopify, 'shop-4');

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Edited In SaaS', 'sku' => 'PT-EDIT-1', 'type' => 'simple', 'price' => 40, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    Http::fake([
        'pt-woo.example.com/*' => Http::response(['id' => 'woo-4'], 200),
        'pt-shopify.myshopify.com/*' => Http::response(['id' => 'shop-4'], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'pt-woo.example.com'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pt-shopify.myshopify.com'));
});

it('does not create or update a Shopify listing unless Shopify is explicitly selected, even with an active Shopify connection', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    ptShopifyConnection($store); // active, but the product has no listing for it and it's never selected
    $product = ptProduct($store, 'PT-WOO-ONLY');
    ptListing($product, $woo, 'woo-5');

    Http::fake([
        'pt-woo.example.com/*' => Http::response(['id' => 'woo-5'], 200),
        'pt-shopify.myshopify.com/*' => Http::response(['id' => 'should-not-be-called'], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'pt-shopify.myshopify.com'));
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->where('platform_connection_id', '!=', $woo->id)->exists()))->toBeFalse();
});

it('rejects a selected connection that belongs to another store', function (): void {
    [, $storeA] = ptWorkspace('Publish Store A');
    [$ownerB, $storeB] = ptWorkspace('Publish Store B');
    $productB = ptProduct($storeB, 'PT-FOREIGN-1');

    // ownerB's OWN product, but with a connection id belonging to store A —
    // the important thing is the service re-verifies store ownership itself,
    // not just the controller-level abort_if on the product.
    $foreignConnectionForA = ptWooConnection($storeA, 'store-a-conn.example.com');

    Http::fake(['*' => Http::response(['id' => 'x'], 200)]);

    $response = $this->actingAs($ownerB)->postJson("/dashboard/products/{$productB->id}/publish", [
        'connection_ids' => [$foreignConnectionForA->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('failed')
        ->and($response->json('results.0.message'))->toContain("does not belong to this product's store");

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'store-a-conn.example.com'));
});

it('rejects a selected connection that belongs to another organization', function (): void {
    [, $storeA] = ptWorkspace('Org A Publish Store');
    [$ownerB, $storeB] = ptWorkspace('Org B Publish Store');
    $orgAConnection = ptWooConnection($storeA, 'org-a-conn.example.com');
    $productB = ptProduct($storeB, 'PT-ORG-1');

    Http::fake(['*' => Http::response(['id' => 'x'], 200)]);

    $response = $this->actingAs($ownerB)->postJson("/dashboard/products/{$productB->id}/publish", [
        'connection_ids' => [$orgAConnection->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('failed');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'org-a-conn.example.com'));
});

it('skips a connection with no ProductChannelListing by default, reporting not_linked_to_channel', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $product = ptProduct($store, 'PT-UNLINKED-1');
    // No listing created for $woo.

    Http::fake(['*' => Http::response(['id' => 'should-not-be-called'], 200)]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('skipped')
        ->and($response->json('results.0.message'))->toBe('not_linked_to_channel');

    Http::assertNothingSent();
});

it('does not duplicate the remote listing across repeated create-missing publishes', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $product = ptProduct($store, 'PT-NO-DUPE-1');

    Http::fake([
        'pt-woo.example.com/*' => Http::sequence()
            ->push(['id' => 'woo-created-1'], 200) // create
            ->push(['id' => 'woo-created-1'], 200), // update
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)
        ->where('platform_connection_id', $woo->id)
        ->count()))->toBe(1);
});

it('includes succeeded/failed/skipped status per connection in the response', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    // A store can only have one connection per platform — use a distinct
    // platform for the "unlinked" leg of this test.
    $unlinked = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'youcan', 'status' => 'active', 'access_token' => 'yc_test',
    ]));

    $product = ptProduct($store, 'PT-RESULTS-1');
    ptListing($product, $woo, 'woo-6');

    Http::fake(['pt-woo.example.com/*' => Http::response(['id' => 'woo-6'], 200)]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id, $unlinked->id],
    ])->assertOk();

    $statuses = collect($response->json('results'))->pluck('status')->all();
    expect($statuses)->toContain('succeeded')->toContain('skipped');
});

it('preserves the existing ProductChannelListing id and external id after a successful publish', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $product = ptProduct($store, 'PT-PRESERVE-1');
    $listing = ptListing($product, $woo, 'woo-preserve-1');

    Http::fake(['pt-woo.example.com/*' => Http::response(['id' => 'woo-preserve-1'], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    $fresh = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id));
    expect($fresh)->not->toBeNull()
        ->and($fresh->external_product_id)->toBe('woo-preserve-1');
});

it('preserves an existing ProductVariantChannelListing when publishing a variable product', function (): void {
    [$owner, $store] = ptWorkspace();
    $woo = ptWooConnection($store);
    $product = ptProduct($store, 'PT-VAR-PARENT', 'variable');
    $listing = ptListing($product, $woo, 'woo-var-parent');

    // Canonical variant — publish now routes WooCommerce through the same
    // ProductAttribute/ProductAttributeValue/ProductVariant mapper Shopify
    // uses. A legacy `attributes`-JSON-only variant (no canonical pivot)
    // would make readiness block the whole publish, which used to leave
    // this test passing for the wrong reason — nothing was ever sent, the
    // pre-seeded row just never got touched.
    app(\App\Services\Catalog\ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Red']],
    ], [
        ['sku' => 'PT-VAR-RED', 'price' => 35, 'options' => ['Color' => 'Red']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $woo->id,
        'external_variant_id' => 'woo-var-red-1',
        'sync_status' => 'synced',
    ]));

    Http::fake([
        'pt-woo.example.com/wp-json/wc/v3/products/woo-var-parent' => Http::response(['id' => 'woo-var-parent'], 200),
        'pt-woo.example.com/wp-json/wc/v3/products/woo-var-parent/variations/woo-var-red-1' => Http::response(['id' => 'woo-var-red-1'], 200),
    ]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('results.0.status'))->toBe('succeeded');
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/products/woo-var-parent/variations/woo-var-red-1'));

    $freshVariantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->find($variantListing->id));
    expect($freshVariantListing)->not->toBeNull()
        ->and($freshVariantListing->external_variant_id)->toBe('woo-var-red-1');
});
