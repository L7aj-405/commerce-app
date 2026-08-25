<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Phase S1 — queued publish (/publish-queued) is the official UI publish
 * action. The synchronous /publish route stays available for backward
 * compatibility/tests but must never publish implicitly to every connected
 * platform, and no route exposes the old unsafe "publish to all" /push
 * endpoint at all.
 */

/** @return array{0: User, 1: Store} */
function ppcpWorkspace(string $name = 'Publish Canonical Path Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ppcpProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Canonical Path Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));
}

it('does not register any route for the old unsafe publish-to-all /push endpoint', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());

    expect($routes->contains(fn ($uri) => str_ends_with($uri, '/push')))->toBeFalse();
});

it('requires explicit connection_ids for the synchronous publish endpoint', function (): void {
    [$owner, $store] = ppcpWorkspace();
    $product = ppcpProduct($store, 'PPCP-1');

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [])
        ->assertStatus(422);
});

it('requires explicit connection_ids for the queued publish endpoint', function (): void {
    [$owner, $store] = ppcpWorkspace();
    $product = ppcpProduct($store, 'PPCP-2');

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish-queued", [])
        ->assertStatus(422);
});

it('queued publish returns a batch_id and creates a ProductPublishResult per connection', function (): void {
    [$owner, $store] = ppcpWorkspace();
    $woo = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://ppcp3-woo.example.com', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
    $product = ppcpProduct($store, 'PPCP-3');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'ppcp-3-remote', 'sync_status' => 'synced',
    ]));

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish-queued", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('status'))->toBe('queued')
        ->and($response->json('batch_id'))->not->toBeNull();

    $batch = \App\Models\ProductPublishBatch::withoutTenancy(fn () => \App\Models\ProductPublishBatch::query()->find($response->json('batch_id')));
    expect($batch)->not->toBeNull()
        ->and($batch->total_count)->toBe(1);
});

it('does not publish to every connected platform implicitly when only one connection_id is selected', function (): void {
    [$owner, $store] = ppcpWorkspace();
    $woo = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://ppcp4-woo.example.com', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'ppcp4-shop.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $product = ppcpProduct($store, 'PPCP-4');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'ppcp-4-woo-remote', 'sync_status' => 'synced',
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'ppcp-4-shop-remote', 'sync_status' => 'synced',
    ]));

    Http::fake([
        'ppcp4-woo.example.com/*' => Http::response(['id' => 'ppcp-4-woo-remote'], 200),
        'ppcp4-shop.myshopify.com/*' => Http::response(['product' => ['id' => 'should-not-be-called']], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'ppcp4-woo.example.com'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ppcp4-shop.myshopify.com'));
});
