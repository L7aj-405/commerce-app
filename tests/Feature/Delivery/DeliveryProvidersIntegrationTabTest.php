<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\Store;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Delivery Companies tab of the Integrations Center: Ozon Express and
| Sendit (both real, connectable providers), plus Amana as a coming-soon
| placeholder. Each provider's "Manage" action must open its own existing
| setup page — never a duplicate UI.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function dpOwnerWorkspace(string $name = 'Delivery Providers Tab Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('shows Ozon Express and Sendit as not connected, plus Amana as coming soon, when nothing is configured', function (): void {
    [$owner] = dpOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where('delivery', function ($cards) {
            $byCode = collect($cards)->keyBy('code');

            return $byCode['ozon']['status'] === 'not_connected'
                && $byCode['ozon']['coming_soon'] === false
                && $byCode['ozon']['manage_url'] === '/dashboard/delivery-connections'
                && $byCode['sendit']['status'] === 'not_connected'
                && $byCode['sendit']['coming_soon'] === false
                && $byCode['sendit']['is_available'] === true
                && $byCode['sendit']['manage_url'] === '/dashboard/delivery-connections/sendit'
                && $byCode['amana']['status'] === 'coming_soon'
                && $byCode['amana']['is_available'] === false;
        }));
});

it('reflects a connected Ozon Express connection on the delivery tab', function (): void {
    [$owner, $store] = dpOwnerWorkspace('Connected Ozon Store');

    DeliveryConnection::query()->create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where('delivery', fn ($cards) => collect($cards)->firstWhere('code', 'ozon')['status'] === 'connected'
            && collect($cards)->firstWhere('code', 'ozon')['is_connected'] === true));
});

it('reflects an errored Ozon Express connection on the delivery tab', function (): void {
    [$owner, $store] = dpOwnerWorkspace('Errored Ozon Store');

    DeliveryConnection::query()->create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'],
        'status' => DeliveryConnection::STATUS_ERROR, 'last_error' => 'Ozon rejected the credentials.',
    ]);

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where('delivery', fn ($cards) => collect($cards)->firstWhere('code', 'ozon')['status'] === 'error'));
});

it('reflects a connected Sendit connection on the delivery tab', function (): void {
    [$owner, $store] = dpOwnerWorkspace('Connected Sendit Store');

    DeliveryConnection::query()->create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where('delivery', fn ($cards) => collect($cards)->firstWhere('code', 'sendit')['status'] === 'connected'
            && collect($cards)->firstWhere('code', 'sendit')['is_connected'] === true));
});

it('reflects an errored Sendit connection on the delivery tab', function (): void {
    [$owner, $store] = dpOwnerWorkspace('Errored Sendit Store');

    DeliveryConnection::query()->create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'status' => DeliveryConnection::STATUS_ERROR, 'last_error' => 'Sendit rejected the credentials.',
    ]);

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where('delivery', fn ($cards) => collect($cards)->firstWhere('code', 'sendit')['status'] === 'error'));
});

it('opens the existing Ozon setup page from the delivery tab\'s Manage action without a duplicate UI', function (): void {
    [$owner, $store] = dpOwnerWorkspace('Manage Link Store');

    $connection = DeliveryConnection::query()->create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    // Never a second delivery-connection route registered elsewhere — the
    // card just points at the same, single, pre-existing setup page.
    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where(
            'delivery',
            fn ($cards) => collect($cards)->firstWhere('code', 'ozon')['manage_url'] === '/dashboard/delivery-connections',
        ));

    $this->actingAs($owner)->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Delivery/Connections')
            ->where('connection.id', $connection->id)
            ->where('connection.status', DeliveryConnection::STATUS_CONNECTED));
});

it('opens the Sendit setup page from the delivery tab\'s Manage action, distinct from Ozon\'s page', function (): void {
    [$owner, $store] = dpOwnerWorkspace('Sendit Manage Link Store');

    $connection = DeliveryConnection::query()->create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where(
            'delivery',
            fn ($cards) => collect($cards)->firstWhere('code', 'sendit')['manage_url'] === '/dashboard/delivery-connections/sendit',
        ));

    $this->actingAs($owner)->get('/dashboard/delivery-connections/sendit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Delivery/SenditConnections')
            ->where('connection.id', $connection->id)
            ->where('connection.status', DeliveryConnection::STATUS_CONNECTED));
});
