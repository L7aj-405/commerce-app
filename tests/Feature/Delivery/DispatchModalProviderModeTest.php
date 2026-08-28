<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;

/*
|--------------------------------------------------------------------------
| Dispatch modal — Integrated Provider tab readiness. DepartmentController::
| dispatch() computes `orders.*.dispatch_readiness.{ozon,sendit}` (available/
| connected/status/ready/reasons) for every online order still awaiting a
| carrier — this is what the modal's Integrated Provider tab (and the order
| card's quick-send buttons) read to decide whether Ozon/Sendit can accept
| the order right now, and exactly why not if they can't.
|--------------------------------------------------------------------------
*/

function dmpWorkspace(): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $role = $store->roles()->where('name', 'Dispatcher')->firstOrFail();
    $dispatcher = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $dispatcher->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return [$owner, $store, $dispatcher];
}

function dmpReadyOrder(Store $store, User $owner, ?City $city = null): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Test', 'shipping_city_id' => $city?->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return $order;
}

it('lists Ozon as available and ready when connected, mapped, and the order is complete', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'], 'settings' => [],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $order = dmpReadyOrder($store, $owner, $city);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.id', $order->id)
            ->where('orders.0.dispatch_readiness.ozon.available', true)
            ->where('orders.0.dispatch_readiness.ozon.connected', true)
            ->where('orders.0.dispatch_readiness.ozon.ready', true)
            ->where('orders.0.dispatch_readiness.ozon.reasons', []));
});

it('lists Sendit as available and ready when connected, mapped, and a pickup district is configured', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'RAB', 'name' => 'Rabat', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'sendit', 'provider_city_id' => '7', 'city_name' => 'Rabat']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => ['default_pickup_district_id' => '46'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $order = dmpReadyOrder($store, $owner, $city);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.dispatch_readiness.sendit.available', true)
            ->where('orders.0.dispatch_readiness.sendit.connected', true)
            ->where('orders.0.dispatch_readiness.sendit.ready', true));
});

it('disables Send to Ozon with a clear reason when the order city is not mapped', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    // No DeliveryProviderCity/mapping created for this city.
    DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'], 'settings' => [],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    dmpReadyOrder($store, $owner, $city);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.dispatch_readiness.ozon.ready', false)
            ->where('orders.0.dispatch_readiness.ozon.reasons', fn ($reasons) => collect($reasons)->contains(fn ($r) => str_contains($r, 'no Ozon mapping was found'))));
});

it('disables Send to Sendit with a clear reason when the order city has no district mapping', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'FES', 'name' => 'Fes', 'is_active' => true]);
    DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => ['default_pickup_district_id' => '46'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    dmpReadyOrder($store, $owner, $city);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.dispatch_readiness.sendit.ready', false)
            ->where('orders.0.dispatch_readiness.sendit.reasons', fn ($reasons) => collect($reasons)->contains(fn ($r) => str_contains($r, 'no Sendit mapping was found'))));
});

it('disables Send to Sendit with a clear reason when no default pickup district is configured', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'MEK', 'name' => 'Meknes', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'sendit', 'provider_city_id' => '3', 'city_name' => 'Meknes']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => [], // no default_pickup_district_id
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    dmpReadyOrder($store, $owner, $city);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.dispatch_readiness.sendit.ready', false)
            ->where('orders.0.dispatch_readiness.sendit.reasons', fn ($reasons) => collect($reasons)->contains(fn ($r) => str_contains($r, 'pickup district'))));
});

it('marks a provider unavailable (never just "not ready") when no connection exists at all', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    dmpReadyOrder($store, $owner);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.dispatch_readiness.ozon.available', false)
            ->where('orders.0.dispatch_readiness.ozon.ready', false)
            ->where('orders.0.dispatch_readiness.sendit.available', false));
});

it('omits dispatch_readiness once the order already has a shipment', function () {
    [$owner, $store, $dispatcher] = dmpWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);
    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'], 'settings' => [],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
    $order = dmpReadyOrder($store, $owner, $city);

    app(\App\Services\Orders\DispatchService::class)->assign($order, ['carrier_type' => 'courier', 'carrier_name' => 'Amana'], $owner);

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.dispatch_readiness', null));
});
