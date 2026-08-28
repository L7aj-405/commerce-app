<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Integrations Center — /dashboard/integrations as the single entry point
| for commerce, delivery, and tools providers.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function icOwnerWorkspace(string $name = 'Integrations Center Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('renders the Integrations Center with commerce, delivery, and tools categories for the store owner', function (): void {
    [$owner] = icOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->has('commerce')
            ->has('delivery')
            ->has('tools'));
});

it('lists Shopify, WooCommerce, and YouCan under the commerce category', function (): void {
    [$owner] = icOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations')
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->where('commerce', fn ($cards) => collect($cards)->pluck('code')->sort()->values()->all()
                === ['shopify', 'woocommerce', 'youcan'])
            ->where('commerce.0.category', 'commerce'));
});

it('lists WhatsApp under the tools category', function (): void {
    [$owner] = icOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations')
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->where('tools', fn ($cards) => collect($cards)->pluck('code')->contains('whatsapp'))
            ->where('tools', fn ($cards) => collect($cards)->firstWhere('code', 'whatsapp')['category'] === 'tools'));
});

it('reports connected/error/not connected card statuses correctly for commerce platforms', function (): void {
    [$owner, $store] = icOwnerWorkspace();

    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'status' => 'active',
        'shop_domain' => 'ic-shop.myshopify.com', 'access_token' => 'tok',
    ]));
    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'error',
        'api_url' => 'https://ic-woo.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));
    // youcan: no connection at all -> not_connected.

    $this->actingAs($owner)->get('/dashboard/integrations')
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->where('commerce', function ($cards) {
                $byCode = collect($cards)->keyBy('code');

                return $byCode['shopify']['status'] === 'connected'
                    && $byCode['shopify']['is_connected'] === true
                    && $byCode['woocommerce']['status'] === 'error'
                    && $byCode['woocommerce']['is_connected'] === false
                    && $byCode['youcan']['status'] === 'not_connected';
            }));
});

it('never exposes credentials through the Integrations Center card shape', function (): void {
    [$owner, $store] = icOwnerWorkspace();

    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'status' => 'active', 'shop_domain' => 'ic-secret.myshopify.com',
        'consumer_key' => 'client-id', 'consumer_secret' => 'super-secret-value',
    ]));

    $response = $this->actingAs($owner)->get('/dashboard/integrations')->assertOk();

    expect($response->getContent())->not->toContain('super-secret-value');
});

it('scopes Integrations Center cards to the caller\'s own store', function (): void {
    [$ownerA, $storeA] = icOwnerWorkspace('IC Store A');
    [, $storeB] = icOwnerWorkspace('IC Store B');

    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $storeB->id, 'platform' => 'shopify', 'status' => 'active',
        'shop_domain' => 'other-store.myshopify.com', 'access_token' => 'tok',
    ]));

    // Owner A has no connection of their own for storeA — must see not_connected,
    // never store B's "connected" state.
    $this->actingAs($ownerA)->get('/dashboard/integrations')
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->where('commerce', fn ($cards) => collect($cards)->firstWhere('code', 'shopify')['status'] === 'not_connected'));
});
