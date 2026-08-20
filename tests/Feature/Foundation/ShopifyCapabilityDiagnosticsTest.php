<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Shopify\ShopifyCapabilityDiagnosticsService;
use Illuminate\Support\Facades\Http;

function cdWorkspace(string $name = 'Diagnostics Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cdConnection(Store $store, string $domain = 'cd-shop.myshopify.com'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => $domain,
        'consumer_key' => 'cd-client-id',
        'consumer_secret' => 'cd-client-secret',
        'status' => 'active',
    ]));
}

function cdTokenFake(string $domain, string $scope = 'read_products,write_products,read_orders,read_locations'): array
{
    return ["{$domain}/admin/oauth/access_token" => Http::response(
        ['access_token' => 'shpca_diag_token', 'scope' => $scope, 'expires_in' => 86399],
        200
    )];
}

it('fails clearly when token generation fails', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-badcreds.myshopify.com');

    Http::fake(['cd-badcreds.myshopify.com/admin/oauth/access_token' => Http::response(['error' => 'invalid_client'], 401)]);

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);

    expect($report['status'])->toBe('failed')
        ->and($report['token']['generated'])->toBeFalse();

    foreach ($report['capabilities'] as $capability) {
        expect($capability['status'])->toBe('skipped');
    }
});

it('passes when shop access and products endpoint both return 200', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-passing.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-passing.myshopify.com'), [
        'cd-passing.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-passing.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-passing.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-passing.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);
    $byKey = collect($report['capabilities'])->keyBy('key');

    expect($byKey['shop.read']['status'])->toBe('passed')
        ->and($byKey['products.read']['status'])->toBe('passed');
});

it('clears a stale missing-read-products error once products read actually succeeds', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-stale.myshopify.com');
    $conn->update(['settings' => [
        'token_status' => 'failed',
        'last_token_error' => 'Missing read_products scope or app version not released with required scopes.',
    ]]);

    Http::fake(array_merge(cdTokenFake('cd-stale.myshopify.com'), [
        'cd-stale.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-stale.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-stale.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-stale.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    app(ShopifyCapabilityDiagnosticsService::class)->run($conn);

    $fresh = $conn->fresh();
    expect($fresh->settings['token_status'])->toBe('valid')
        ->and($fresh->settings['last_token_error'] ?? null)->toBeNull();
});

it('marks products.read failed on a 403 without faking success', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-products403.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-products403.myshopify.com'), [
        'cd-products403.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-products403.myshopify.com/admin/api/*/products.json*' => Http::response(['errors' => 'forbidden'], 403),
        'cd-products403.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-products403.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);
    $byKey = collect($report['capabilities'])->keyBy('key');

    expect($byKey['products.read']['status'])->toBe('failed')
        ->and($report['status'])->not->toBe('connected');
});

it('marks orders.read failed on a 403 without breaking products.read', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-orders403.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-orders403.myshopify.com'), [
        'cd-orders403.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-orders403.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-orders403.myshopify.com/admin/api/*/orders.json*' => Http::response(['errors' => 'forbidden'], 403),
        'cd-orders403.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);
    $byKey = collect($report['capabilities'])->keyBy('key');

    expect($byKey['orders.read']['status'])->toBe('failed')
        ->and($byKey['products.read']['status'])->toBe('passed');
});

it('reports status connected when shop.read and products.read (and other reads) all pass', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-allgood.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-allgood.myshopify.com'), [
        'cd-allgood.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-allgood.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-allgood.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-allgood.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);

    expect($report['status'])->toBe('connected');
});

it('reports status partially_configured when products pass but orders fail', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-partial.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-partial.myshopify.com'), [
        'cd-partial.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-partial.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-partial.myshopify.com/admin/api/*/orders.json*' => Http::response(['errors' => 'forbidden'], 403),
        'cd-partial.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);

    expect($report['status'])->toBe('partially_configured');
});

it('never exposes client_secret or the generated access token in the response', function (): void {
    [, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-secrets.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-secrets.myshopify.com'), [
        'cd-secrets.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-secrets.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-secrets.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-secrets.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $report = app(ShopifyCapabilityDiagnosticsService::class)->run($conn);
    $serialized = json_encode($report);

    expect($serialized)->not->toContain('cd-client-secret')
        ->and($serialized)->not->toContain('shpca_diag_token');
});

it('only ever touches the acting users own store connection, never another stores', function (): void {
    [, $storeA] = cdWorkspace('Diagnostics Store A');
    [$ownerB, $storeB] = cdWorkspace('Diagnostics Store B');

    $connA = cdConnection($storeA, 'cd-store-a.myshopify.com');
    $connB = cdConnection($storeB, 'cd-store-b.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-store-b.myshopify.com'), [
        'cd-store-b.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-store-b.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-store-b.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-store-b.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $this->actingAs($ownerB)->postJson('/dashboard/integrations/shopify/diagnostics')->assertOk();

    expect($connA->fresh()->settings ?? [])->not->toHaveKey('diagnostics')
        ->and($connB->fresh()->settings['diagnostics']['status'] ?? null)->toBe('connected');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cd-store-a.myshopify.com'));
});

it('never shows a failed connection status alongside a passed products.read capability in page props', function (): void {
    [$owner, $store] = cdWorkspace();
    $conn = cdConnection($store, 'cd-uiconsistency.myshopify.com');

    Http::fake(array_merge(cdTokenFake('cd-uiconsistency.myshopify.com'), [
        'cd-uiconsistency.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'cd-uiconsistency.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'cd-uiconsistency.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'cd-uiconsistency.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]));

    $this->actingAs($owner)->postJson('/dashboard/integrations/shopify/diagnostics')->assertOk();

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page
            ->where('connection.diagnostics.status', fn ($status) => $status !== 'failed')
            ->where('connection.diagnostics.capabilities', fn ($capabilities) => collect($capabilities)
                ->firstWhere('key', 'products.read')['status'] === 'passed'));
});
