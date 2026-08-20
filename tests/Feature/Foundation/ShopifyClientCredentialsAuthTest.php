<?php

declare(strict_types=1);

use App\Connectors\ShopifyConnector;
use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Shopify\ShopifyAuthException;
use App\Services\Shopify\ShopifyAuthService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function sccWorkspace(string $name = 'Shopify CC Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function sccConnection(Store $store, string $domain = 'cc-test-shop.myshopify.com'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => $domain,
        'consumer_key' => 'client-id-123',
        'consumer_secret' => 'client-secret-456',
        'status' => 'active',
    ]));
}

function sccFakeTokenResponse(string $scope = 'read_products,write_products', int $expiresIn = 86399): array
{
    return ['access_token' => 'shpca_generated_token', 'scope' => $scope, 'expires_in' => $expiresIn];
}

it('stores shop_domain and client_id when saving the admin client-credentials method', function (): void {
    [$owner, $store] = sccWorkspace();

    $this->actingAs($owner)->post('/dashboard/integrations/shopify', [
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => 'https://my-test-store.myshopify.com/',
        'client_id' => 'abc123',
        'client_secret' => 'shh-secret',
    ])->assertRedirect(route('dashboard.integrations.shopify'));

    $conn = PlatformConnection::query()->where('store_id', $store->id)->where('platform', 'shopify')->firstOrFail();

    expect($conn->connection_method)->toBe('admin_client_credentials')
        ->and($conn->shop_domain)->toBe('my-test-store.myshopify.com')
        ->and($conn->consumer_key)->toBe('abc123');
});

it('never exposes client_secret to the frontend and stores it encrypted', function (): void {
    [$owner, $store] = sccWorkspace();
    $conn = sccConnection($store);

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page
            ->where('connection.client_id', 'client-id-123')
            ->where('connection.has_client_secret', true)
            ->missing('connection.client_secret')
            ->missing('connection.consumer_secret'));

    $raw = DB::table('platform_connections')->where('id', $conn->id)->value('consumer_secret');
    expect($raw)->not->toBe('client-secret-456');
});

it('test connection generates a token via the client_credentials grant', function (): void {
    [$owner, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake([
        'cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200),
        'cc-test-shop.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]);

    $response = $this->actingAs($owner)->post('/dashboard/integrations/test/shopify');
    $response->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/oauth/access_token')
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === 'client-id-123'
        && $request['client_secret'] === 'client-secret-456');
});

it('sends X-Shopify-Access-Token (not Authorization) on product sync requests', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake([
        'cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200),
        'cc-test-shop.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'shopify');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/products.json')
        && $request->hasHeader('X-Shopify-Access-Token', 'shpca_generated_token')
        && ! $request->hasHeader('Authorization'));
});

it('caches the generated token and reuses it until expiry', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake(['cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200)]);

    $service = app(ShopifyAuthService::class);
    $first = $service->getToken($conn);
    $second = $service->getToken($conn);

    expect($first)->toBe('shpca_generated_token')->and($second)->toBe($first);
    Http::assertSentCount(1);
});

it('regenerates the token once the cache entry is gone', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake(['cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200)]);

    $service = app(ShopifyAuthService::class);
    $service->getToken($conn);
    Cache::forget('shopify:connection:' . $conn->id . ':admin_token');
    $service->getToken($conn);

    Http::assertSentCount(2);
});

it('marks the connection failed with a clear message when the token has empty scopes', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake(['cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(''), 200)]);

    $result = app(ShopifyAuthService::class)->testConnection($conn);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('Token generated but has no scopes');
});

it('reports a missing-scope message when the products endpoint returns 403', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake([
        'cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200),
        'cc-test-shop.myshopify.com/admin/api/*/products.json*' => Http::response(['errors' => 'forbidden'], 403),
    ]);

    $result = app(ShopifyAuthService::class)->testConnection($conn);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBe('Missing read_products scope or app version not released with required scopes.')
        ->and($conn->fresh()->settings['token_status'])->toBe('failed');
});

it('normalizes a valid shop domain and rejects the admin.shopify.com URL format', function (): void {
    $service = app(ShopifyAuthService::class);

    expect($service->normalizeShopDomain('https://foo.myshopify.com/'))->toBe('foo.myshopify.com');

    expect(fn () => $service->normalizeShopDomain('admin.shopify.com/store/foo'))
        ->toThrow(ShopifyAuthException::class);
});

it('leaves the webhook connection method unaffected by the client-credentials changes', function (): void {
    [$owner, $store] = sccWorkspace();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'webhook',
        'shop_domain' => 'webhook-still-works.myshopify.com',
        'webhook_secret' => 'wh-secret',
        'status' => 'pending',
        'webhook_status' => 'pending',
    ]));

    $this->actingAs($owner)
        ->get('/dashboard/integrations/shopify')
        ->assertInertia(fn ($page) => $page->where('connection.connection_method', 'webhook'));
});

it('clears a stale last_token_error once a test connection succeeds', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);
    $conn->update(['settings' => ['token_status' => 'failed', 'last_token_error' => 'Missing read_products scope or app version not released with required scopes.']]);

    Http::fake([
        'cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200),
        'cc-test-shop.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]);

    $result = app(ShopifyAuthService::class)->testConnection($conn);

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toBe('Connected successfully. Products API is reachable.');

    $fresh = $conn->fresh();
    expect($fresh->settings['token_status'])->toBe('valid')
        ->and($fresh->settings['last_token_error'] ?? null)->toBeNull();
});

it('marks the connection valid with no error when the products endpoint returns 200', function (): void {
    [, $store] = sccWorkspace();
    $conn = sccConnection($store);

    Http::fake([
        'cc-test-shop.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse(), 200),
        'cc-test-shop.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
    ]);

    app(ShopifyAuthService::class)->testConnection($conn);

    $fresh = $conn->fresh();
    expect($fresh->settings['token_status'])->toBe('valid')
        ->and($fresh->settings['last_token_error'] ?? null)->toBeNull()
        ->and($fresh->settings['last_token_generated_at'] ?? null)->not->toBeNull();
});

it('never persists both a valid token_status and a non-null last_token_error at the same time', function (): void {
    [, $store] = sccWorkspace();

    // Scope present but not read_products — was the exact bug: requestToken()
    // marked the connection valid, then the read_products check failed
    // without correcting it back.
    $conn = sccConnection($store, 'consistency-check.myshopify.com');
    Http::fake(['consistency-check.myshopify.com/admin/oauth/access_token' => Http::response(sccFakeTokenResponse('write_products'), 200)]);

    app(ShopifyAuthService::class)->testConnection($conn);

    $settings = $conn->fresh()->settings;
    $isValid = ($settings['token_status'] ?? null) === 'valid';
    $hasError = filled($settings['last_token_error'] ?? null);

    expect($isValid && $hasError)->toBeFalse();
    expect($settings['token_status'])->toBe('failed');
});
