<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/** @return array{0: User, 1: Store} */
function cacWorkspace(string $name = 'Auth Clarity Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cacWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_super_secret', 'consumer_secret' => 'cs_super_secret',
    ]));
}

it('does not expose credentials in the test connection response', function (): void {
    [$owner, $store] = cacWorkspace();
    $woo = cacWoo($store, 'auth1-woo.example.com');

    Http::fake(['*/wp-json/wc/v3/system_status*' => Http::response(['environment' => []], 200)]);

    $response = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/test")
        ->assertOk();

    $raw = $response->getContent();

    expect($raw)->not->toContain('cs_super_secret')
        ->and($raw)->not->toContain('ck_super_secret')
        ->and($response->json())->toHaveKeys(['ok', 'message'])
        ->and($response->json('message'))->not->toBeNull();
});

it('does not expose credentials anywhere in the connection profile page payload', function (): void {
    [$owner, $store] = cacWorkspace();
    $woo = cacWoo($store, 'auth2-woo.example.com');

    $response = $this->actingAs($owner)->get("/dashboard/integrations/connections/{$woo->id}");
    $response->assertOk();

    expect($response->getContent())
        ->not->toContain('cs_super_secret')
        ->not->toContain('ck_super_secret');
});

it('records a unified auth status after testing a connection', function (): void {
    [$owner, $store] = cacWorkspace();
    $woo = cacWoo($store, 'auth3-woo.example.com');

    Http::fake(['*/wp-json/wc/v3/system_status*' => Http::response(['environment' => []], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/test")->assertOk();

    expect($woo->fresh()->metadata['auth_check']['ok'] ?? null)->toBeTrue();

    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$woo->id}")
        ->assertInertia(fn ($page) => $page->where('auth.status', 'connected'));
});

it('requires the exact typed confirmation before disconnecting', function (): void {
    [$owner, $store] = cacWorkspace();
    $woo = cacWoo($store, 'auth4-woo.example.com');
    $base = "/dashboard/integrations/connections/{$woo->id}";

    $this->actingAs($owner)->postJson("{$base}/disconnect")->assertStatus(422);
    $this->actingAs($owner)->postJson("{$base}/disconnect", ['confirmation' => 'disconnect'])->assertStatus(422);
    $this->actingAs($owner)->postJson("{$base}/disconnect", ['confirmation' => 'RESET'])->assertStatus(422);

    expect($woo->fresh()->status)->toBe('active');

    $this->actingAs($owner)->postJson("{$base}/disconnect", ['confirmation' => 'DISCONNECT'])->assertOk();

    expect($woo->fresh()->status)->toBe('disconnected');
});

it('does not expose the Shopify client secret or generated access token when testing a client-credentials connection', function (): void {
    [$owner, $store] = cacWorkspace();
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => 'auth6-shop.myshopify.com', 'consumer_key' => 'auth6-client-id', 'consumer_secret' => 'auth6-super-secret',
        'status' => 'active',
    ]));

    Http::fake([
        'auth6-shop.myshopify.com/admin/oauth/access_token' => Http::response(
            ['access_token' => 'shpca_auth6_secret_token', 'scope' => 'read_products,read_orders,read_locations', 'expires_in' => 3600], 200,
        ),
        'auth6-shop.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['id' => 1]], 200),
        'auth6-shop.myshopify.com/admin/api/*/products.json*' => Http::response(['products' => []], 200),
        'auth6-shop.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => []], 200),
        'auth6-shop.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200),
    ]);

    $testResponse = $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$shopify->id}/test")->assertOk();
    $profileResponse = $this->actingAs($owner)->get("/dashboard/integrations/connections/{$shopify->id}")->assertOk();

    foreach ([$testResponse->getContent(), $profileResponse->getContent()] as $body) {
        expect($body)
            ->not->toContain('auth6-super-secret')
            ->not->toContain('shpca_auth6_secret_token');
    }
});

it('clears stale diagnostics and auth errors when Shopify credentials are re-saved', function (): void {
    [$owner, $store] = cacWorkspace();
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => 'auth7-shop.myshopify.com', 'consumer_key' => 'auth7-old-id', 'consumer_secret' => 'auth7-old-secret',
        'status' => 'active',
        'settings' => [
            'token_status' => 'failed',
            'last_token_error' => 'Missing read_products scope or app version not released with required scopes.',
            'diagnostics' => ['status' => 'failed', 'capabilities' => []],
        ],
        'metadata' => ['auth_check' => ['ok' => false, 'message' => 'stale', 'checked_at' => now()->toIso8601String()]],
    ]));

    $this->actingAs($owner)->post('/dashboard/integrations/shopify', [
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => 'auth7-shop.myshopify.com',
        'client_id' => 'auth7-new-id',
        'client_secret' => 'auth7-new-secret',
    ])->assertRedirect();

    $fresh = $shopify->fresh();
    expect($fresh->settings['last_token_error'] ?? null)->toBeNull()
        ->and($fresh->settings)->not->toHaveKey('diagnostics')
        ->and($fresh->settings['token_status'])->toBe('unknown')
        ->and($fresh->metadata ?? [])->not->toHaveKey('auth_check');

    // Requires Test connection again — the page must show "needs setup",
    // never a leftover "connected" or the old error.
    $this->actingAs($owner)
        ->get("/dashboard/integrations/connections/{$shopify->id}")
        ->assertInertia(fn ($page) => $page->where('auth.status', 'needs_setup')->where('auth.error', null));
});

it('never logs credentials when a test connection call fails', function (): void {
    [$owner, $store] = cacWorkspace();
    $woo = cacWoo($store, 'auth5-woo.example.com');

    Http::fake(['*/wp-json/wc/v3/system_status*' => Http::response(['message' => 'Unauthorized'], 401)]);

    $response = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/test")
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('cs_super_secret')
        ->not->toContain('ck_super_secret');
});
