<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Organization, 2: Store} */
function cpWorkspace(string $name = 'Connection Profile Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $organization, $store];
}

function cpWoo(Store $store, string $domain, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ], $overrides)));
}

it('shows the authentication and sync status sections as distinct props', function (): void {
    [$owner, , $store] = cpWorkspace();
    $connection = cpWoo($store, 'profile1-woo.example.com');

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$connection->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Integrations/ConnectionProfile')
            ->has('connection')
            ->has('auth')
            ->where('auth.status', fn ($status) => in_array($status, ['connected', 'needs_setup', 'error'], true))
            ->has('syncStatus')
            ->where('syncStatus.product_mappings_count', 0)
            ->where('syncStatus.variant_mappings_count', 0)
            ->where('syncStatus.imported_orders_count', 0));
});

it('reports live mapping and imported-order counts on the sync status section', function (): void {
    [$owner, , $store] = cpWorkspace();
    $connection = cpWoo($store, 'profile2-woo.example.com');

    $product = \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $store->id, 'name' => 'Profile Product', 'sku' => 'CP-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    \App\Models\ProductChannelListing::withoutTenancy(fn () => \App\Models\ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'woo-cp-1', 'sync_status' => 'synced',
    ]));
    app(\App\Services\Sync\OrderSyncService::class)->saveOrder([
        'platform_id' => 'CP-ORDER-1', 'number' => '#1', 'status' => 'processing', 'total' => 50.0, 'currency' => 'MAD',
        'customer_name' => 'Customer', 'customer_email' => null, 'customer_phone' => null, 'items' => [],
        'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$connection->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('syncStatus.product_mappings_count', 1)
            ->where('syncStatus.imported_orders_count', 1));
});

function cpShopifyClientCredentials(Store $store, string $domain, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => $domain, 'consumer_key' => 'cp-client-id', 'consumer_secret' => 'cp-client-secret',
        'status' => 'active',
    ], $overrides)));
}

it('exposes auth status and per-endpoint capability status as separate props', function (): void {
    [$owner, , $store] = cpWorkspace();
    $connection = cpShopifyClientCredentials($store, 'profile4-shop.myshopify.com', [
        'settings' => [
            'diagnostics' => [
                'status' => 'connected',
                'last_checked_at' => now()->toIso8601String(),
                'token' => ['generated' => true, 'expires_in' => 3600, 'reported_scopes' => ['read_products', 'read_orders', 'read_locations']],
                'capabilities' => [
                    ['key' => 'shop.read', 'label' => 'Shop access', 'status' => 'passed', 'message' => 'Shop API reachable.'],
                    ['key' => 'products.read', 'label' => 'Read products', 'status' => 'passed', 'message' => 'Products API reachable.'],
                    ['key' => 'orders.read', 'label' => 'Read orders', 'status' => 'failed', 'message' => 'Missing read_orders scope or order access not granted.'],
                    ['key' => 'locations.read', 'label' => 'Read locations', 'status' => 'passed', 'message' => 'Locations API reachable.'],
                ],
            ],
        ],
    ]);

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$connection->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Auth status reflects the overall connection verdict...
            ->where('auth.status', 'connected')
            // ...while each endpoint's OWN capability status is reported
            // separately and can legitimately disagree with it (orders
            // failing here must never drag the top-level status down).
            ->where('auth.capabilities.token', 'ok')
            ->where('auth.capabilities.shop', 'ok')
            ->where('auth.capabilities.products_read', 'ok')
            ->where('auth.capabilities.orders_read', 'error')
            ->where('auth.capabilities.inventory_locations', 'ok'));
});

it('does not let a merchant open another organization\'s connection', function (): void {
    [, , $storeA] = cpWorkspace('Profile Org A');
    [$ownerB] = cpWorkspace('Profile Org B');
    $connectionA = cpWoo($storeA, 'profile3-woo.example.com');

    // PlatformConnection's global tenant scope already excludes it from
    // route-model binding once owner B's active store is resolved — the
    // record simply isn't found, same as any other tenant-scoped model.
    $this->actingAs($ownerB)
        ->get("/dashboard/integrations/connections/{$connectionA->id}")
        ->assertNotFound();
});
