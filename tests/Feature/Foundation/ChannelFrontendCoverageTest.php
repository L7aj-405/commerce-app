<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

/** @return array{0: User, 1: Store} */
function channelCoverageWorkspace(string $name = 'Channel Coverage Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('shows platform, status, and last-synced info for every connected channel on the integrations index', function (): void {
    [$owner, $store] = channelCoverageWorkspace();
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'label' => 'Main Store',
        'api_url' => 'https://ccov-woo.example.com', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        'last_synced_at' => now(), 'synced_products_count' => 12,
    ]));

    $this->actingAs($owner)->get('/dashboard/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->has('providers')
            ->where('connections.0.id', $connection->id)
            ->where('connections.0.platform', 'woocommerce')
            ->where('connections.0.status', 'active')
            ->where('connections.0.synced_products_count', 12));
});

it('never exposes client_secret or access_token on the Shopify integration page', function (): void {
    [$owner, $store] = channelCoverageWorkspace('Shopify Secret Coverage Store');
    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials', 'status' => 'active',
        'shop_domain' => 'ccov-shop.myshopify.com', 'consumer_key' => 'ccov-client-id', 'consumer_secret' => 'super-secret-value',
    ]));

    $this->actingAs($owner)->get('/dashboard/integrations/shopify')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Platforms/Shopify')
            ->where('connection.client_id', 'ccov-client-id')
            ->where('connection.has_client_secret', true)
            ->missing('connection.client_secret')
            ->missing('connection.consumer_secret')
            ->missing('connection.access_token')
            ->has('webhookUrl')
            ->has('webhookEvents'));
});

it('shows the WooCommerce connection status and does not publish products from the integrations page', function (): void {
    [$owner, $store] = channelCoverageWorkspace('WooCommerce Coverage Store');
    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://ccov-woo2.example.com', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));

    $this->actingAs($owner)->get('/dashboard/integrations/woocommerce')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Platforms/WooCommerce')
            ->where('connection.status', 'active'));

    // Publishing lives on product routes, never under /integrations.
    expect(Route::has('dashboard.products.publish'))->toBeTrue();
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'dashboard/integrations') && $route->getName() !== null) {
            expect($route->getName())->not->toContain('publish');
        }
    }
});

it('routes every integrations nav destination to a real, named route', function (): void {
    foreach ([
        'dashboard.integrations.index',
        'dashboard.integrations.woocommerce',
        'dashboard.integrations.shopify',
        'dashboard.integrations.youcan',
        'dashboard.integrations.whatsapp',
        'dashboard.integrations.test',
        'dashboard.integrations.shopify.diagnostics',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }
});

it('does not register a removed /push product endpoint anywhere in the route table', function (): void {
    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'products/{product}/push')) {
            $this->fail("Unexpected legacy publish-to-all route still registered: {$route->uri()}");
        }
    }

    expect(Route::has('dashboard.products.push'))->toBeFalse();
});
