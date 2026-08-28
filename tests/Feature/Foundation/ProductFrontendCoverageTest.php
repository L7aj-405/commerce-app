<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

/** @return array{0: User, 1: Store} */
function pfcWorkspace(string $name = 'Product Frontend Coverage Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('gives the product edit page a readiness prop', function (): void {
    [$owner, $store] = pfcWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Readiness Coverage Product', 'sku' => 'PFC-READY-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));

    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Products/Edit')
            ->has('readiness.shopify')
            ->has('readiness.woocommerce'));
});

it('gives the product edit page a connections prop reflecting the store active connections', function (): void {
    [$owner, $store] = pfcWorkspace('Product Connections Coverage Store');
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://pfc-woo.example.com', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Connections Coverage Product', 'sku' => 'PFC-CONN-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));

    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('connections', 1)
            ->where('connections.0.id', $connection->id)
            ->where('connections.0.platform', 'woocommerce'));
});

it('does not register an old unsafe publish-to-all-connections route', function (): void {
    expect(Route::has('dashboard.products.push'))->toBeFalse();

    // Publish requires an explicit connection_ids selection — never a bare
    // publish-everything action reachable without naming a channel.
    $publishRoute = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'dashboard.products.publish');
    expect($publishRoute)->not->toBeNull()
        ->and($publishRoute->methods())->toContain('POST');
});

it('gives the product index page connected channel status per product', function (): void {
    [$owner, $store] = pfcWorkspace('Product Index Coverage Store');
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'pfc-shop.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Index Coverage Product', 'sku' => 'PFC-INDEX-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'gid-1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->get('/dashboard/products')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Products/Index')
            ->where('products.data.0.channel_listings.0.sync_status', 'synced'));
});

it('exposes distinct sync (pull) and publish (push) product routes', function (): void {
    foreach (['dashboard.products.sync.start', 'dashboard.products.publish', 'dashboard.products.publish-queued', 'dashboard.products.bulk-publish'] as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }

    $syncRoute = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'dashboard.products.sync.start');
    $publishRoute = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'dashboard.products.publish');

    expect($syncRoute->uri())->not->toBe($publishRoute->uri());
});
