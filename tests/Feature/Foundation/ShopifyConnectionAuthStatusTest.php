<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * Root cause: ShopifyAuthService::testConnection() hard-gated on the
 * client-credentials token's self-reported `scope` string containing
 * "read_products" — a token whose scope string simply didn't spell that out
 * (Shopify doesn't always echo scopes consistently) failed the WHOLE
 * connection before ever calling GET /products.json for real, even though
 * that same endpoint is exactly what ProductSyncService uses and it worked
 * fine. ConnectionProfileController::test() now uses
 * ShopifyCapabilityDiagnosticsService for admin_client_credentials Shopify
 * connections instead — real per-endpoint checks, scope string is advisory
 * only — and both syncProducts()/syncOrders() clear a stale scope-related
 * error the moment a real sync actually succeeds.
 */

/** @return array{0: User, 1: Store} */
function scasWorkspace(string $name = 'Shopify Auth Status Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function scasConnection(Store $store, string $domain, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => $domain, 'consumer_key' => 'scas-client-id', 'consumer_secret' => 'scas-client-secret',
        'status' => 'active',
    ], $overrides)));
}

/** @return array<string, mixed> Http::fake map for the oauth token endpoint. */
function scasTokenFake(string $domain, string $scope = ''): array
{
    return ["{$domain}/admin/oauth/access_token" => Http::response(
        ['access_token' => 'shpca_scas_token', 'scope' => $scope, 'expires_in' => 86399],
        200,
    )];
}

it('marks products_read OK when the Shopify products endpoint genuinely succeeds', function (): void {
    [$owner, $store] = scasWorkspace();
    $conn = scasConnection($store, 'scas1.myshopify.com');

    // Scope string present but never literally spells out "read_products" —
    // the exact condition that used to hard-fail the whole connection
    // before the products endpoint was ever actually called.
    Http::fake(array_merge(scasTokenFake('scas1.myshopify.com', 'write_products,read_orders'), [
        'scas1.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'scas1.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'scas1.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'scas1.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $response = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$conn->id}/test")
        ->assertOk();

    expect($response->json('ok'))->toBeTrue();

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$conn->id}")
        ->assertInertia(fn ($page) => $page
            ->where('auth.status', 'connected')
            ->where('auth.capabilities.products_read', 'ok')
            ->where('auth.error', null));
});

it('clears a stale missing-read-products auth error once a product sync actually succeeds', function (): void {
    [$owner, $store] = scasWorkspace();
    $conn = scasConnection($store, 'scas2.myshopify.com', [
        'settings' => [
            'token_status' => 'failed',
            'last_token_error' => 'Missing read_products scope or app version not released with required scopes.',
        ],
    ]);

    Http::fake(array_merge(scasTokenFake('scas2.myshopify.com', 'write_products,read_orders'), [
        'scas2.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]));

    expect($conn->fresh()->settings['token_status'])->toBe('failed');

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$conn->id}/sync-products")
        ->assertOk()
        ->assertJsonPath('ok', true);

    $fresh = $conn->fresh();
    expect($fresh->settings['token_status'])->toBe('valid')
        ->and($fresh->settings['last_token_error'] ?? null)->toBeNull();
});

it('shows a scope warning without marking auth as error when products still succeed', function (): void {
    [$owner, $store] = scasWorkspace();
    $conn = scasConnection($store, 'scas3.myshopify.com');

    // Scope string reports write_products only — never mentions
    // read_products — yet the products endpoint itself returns 200.
    Http::fake(array_merge(scasTokenFake('scas3.myshopify.com', 'write_products'), [
        'scas3.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'scas3.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'scas3.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'scas3.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$conn->id}/test")->assertOk();

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$conn->id}")
        ->assertInertia(fn ($page) => $page
            ->where('auth.status', 'connected')
            ->where('auth.error', null)
            ->where('auth.warning', 'Scope introspection did not confirm read_products, but product API read succeeded.'));
});

it('reports a products capability error when the products endpoint genuinely fails', function (): void {
    [$owner, $store] = scasWorkspace();
    $conn = scasConnection($store, 'scas4.myshopify.com');

    Http::fake(array_merge(scasTokenFake('scas4.myshopify.com', 'read_products,read_orders,read_locations'), [
        'scas4.myshopify.com/admin/api/*/shop.json' => Http::response(['errors' => 'forbidden'], 403),
        'scas4.myshopify.com/admin/api/*/products.json*' => Http::response(['errors' => 'forbidden'], 403),
        'scas4.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'scas4.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $response = $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$conn->id}/test")->assertOk();
    expect($response->json('ok'))->toBeFalse();

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$conn->id}")
        ->assertInertia(fn ($page) => $page
            ->where('auth.status', 'error')
            ->where('auth.capabilities.products_read', 'error'));
});

it('reports an orders capability error without falsely reporting products as missing', function (): void {
    [$owner, $store] = scasWorkspace();
    $conn = scasConnection($store, 'scas5.myshopify.com');

    Http::fake(array_merge(scasTokenFake('scas5.myshopify.com', 'read_products,read_locations'), [
        'scas5.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'scas5.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'scas5.myshopify.com/admin/api/*/orders.json*' => Http::response(['errors' => 'missing read_orders'], 403),
        'scas5.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$conn->id}/test")->assertOk();

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$conn->id}")
        ->assertInertia(fn ($page) => $page
            ->where('auth.status', 'connected') // products/shop passing keeps the connection usable
            ->where('auth.capabilities.orders_read', 'error')
            ->where('auth.capabilities.products_read', 'ok'));
});
