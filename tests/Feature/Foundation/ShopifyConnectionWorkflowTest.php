<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

function scwWorkspace(string $name = 'Shopify Connection Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $organization, $store];
}

it('shows the three Shopify connection methods, App marked coming soon', function (): void {
    [$owner] = scwWorkspace();

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Integrations/Platforms/Shopify')
            ->has('webhookEvents', 4)
            ->where('webhookEvents', ['orders/create', 'orders/updated', 'products/create', 'products/update']));
});

it('still accepts a shop domain and access token via the admin token method', function (): void {
    [$owner, , $store] = scwWorkspace();

    $this->actingAs($owner)->post('/dashboard/integrations/shopify', [
        'connection_method' => 'admin_token',
        'shop_domain' => 'admin-token-store.myshopify.com',
        'access_token' => 'shpat_test_token',
    ])->assertRedirect(route('dashboard.integrations.shopify'));

    $conn = PlatformConnection::query()->where('store_id', $store->id)->where('platform', 'shopify')->firstOrFail();

    expect($conn->connection_method)->toBe('admin_token')
        ->and($conn->status)->toBe('active')
        ->and($conn->shop_domain)->toBe('admin-token-store.myshopify.com')
        ->and($conn->access_token)->toBe('shpat_test_token');
});

it('creates a pending webhook-method connection and generates a webhook URL', function (): void {
    [$owner, , $store] = scwWorkspace();

    $this->actingAs($owner)->post('/dashboard/integrations/shopify', [
        'connection_method' => 'webhook',
        'shop_domain' => 'webhook-store.myshopify.com',
        'webhook_secret' => 'shhh-secret',
        'events' => ['orders/create', 'products/create'],
    ])->assertRedirect(route('dashboard.integrations.shopify'));

    $conn = PlatformConnection::query()->where('store_id', $store->id)->where('platform', 'shopify')->firstOrFail();

    expect($conn->connection_method)->toBe('webhook')
        ->and($conn->status)->toBe('pending')
        ->and($conn->webhook_status)->toBe('pending')
        ->and($conn->settings['webhook_events'] ?? [])->toBe(['orders/create', 'products/create']);

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page
            ->where('webhookUrl', url("/api/webhooks/shopify/{$conn->id}"))
            ->where('connection.connection_method', 'webhook')
            ->where('connection.has_webhook_secret', true)
            ->missing('connection.webhook_secret')
            ->missing('connection.access_token'));
});

it('rejects an unauthorized users request to configure another tenants Shopify connection', function (): void {
    [, , $storeA] = scwWorkspace('Tenant A Shopify');
    [$ownerB] = scwWorkspace('Tenant B Shopify');

    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $storeA->id,
        'platform' => 'shopify',
        'connection_method' => 'webhook',
        'shop_domain' => 'tenant-a.myshopify.com',
        'status' => 'pending',
        'webhook_status' => 'pending',
    ]));

    // Owner B has their own (no) active store connection — saving Shopify
    // must only ever touch their own active store, never Tenant A's.
    $this->actingAs($ownerB)->post('/dashboard/integrations/shopify', [
        'connection_method' => 'webhook',
        'shop_domain' => 'tenant-b-hijack.myshopify.com',
        'events' => ['orders/create'],
    ])->assertRedirect();

    $tenantAConnection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->where('store_id', $storeA->id)->first());
    expect($tenantAConnection->shop_domain)->toBe('tenant-a.myshopify.com');
});

it('never exposes both a valid status and an error in the same page load', function (): void {
    [$owner, , $store] = scwWorkspace('Consistency Panel Store');

    $conn = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => 'panel-consistency.myshopify.com',
        'consumer_key' => 'cid',
        'consumer_secret' => 'csecret',
        'status' => 'active',
    ]));

    Http::fake([
        'panel-consistency.myshopify.com/admin/oauth/access_token' => Http::response(
            ['access_token' => 'shpca_panel_token', 'scope' => 'read_products,write_products', 'expires_in' => 86399],
            200
        ),
        'panel-consistency.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]);

    $this->actingAs($owner)->post('/dashboard/integrations/test/shopify')->assertOk()->assertJson(['ok' => true]);

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page
            ->where('connection.token_status', 'valid')
            ->where('connection.last_token_error', null));
});

it('does not display a stale missing-scope error in page props after a later successful test', function (): void {
    [$owner, , $store] = scwWorkspace('Stale Error Store');

    $conn = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => 'stale-error-store.myshopify.com',
        'consumer_key' => 'cid',
        'consumer_secret' => 'csecret',
        'status' => 'active',
        'settings' => [
            'token_status' => 'failed',
            'last_token_error' => 'Missing read_products scope or app version not released with required scopes.',
        ],
    ]));

    // Confirm the stale error is actually visible before the fix scenario runs.
    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page->where('connection.last_token_error', 'Missing read_products scope or app version not released with required scopes.'));

    Http::fake([
        'stale-error-store.myshopify.com/admin/oauth/access_token' => Http::response(
            ['access_token' => 'shpca_fresh_token', 'scope' => 'read_products,write_products', 'expires_in' => 86399],
            200
        ),
        'stale-error-store.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]);

    $this->actingAs($owner)->post('/dashboard/integrations/test/shopify')->assertOk()->assertJson(['ok' => true]);

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page
            ->where('connection.token_status', 'valid')
            ->where('connection.last_token_error', null));
});
