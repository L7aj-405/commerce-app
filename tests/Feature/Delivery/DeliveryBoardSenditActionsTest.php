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
use App\Services\Delivery\SenditShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Delivery Board (Dispatch) shows Sendit alongside Ozon without disturbing
| the existing Ozon flow — the two providers coexist on the SAME board,
| SAME order_shipments bridge, and the widened Shipment query (no longer
| filtered to provider_code='ozon') never mixes up which provider a given
| in-flight row belongs to.
|--------------------------------------------------------------------------
*/

function boardWorkspace(): array
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

it('reports both ozon_connected and sendit_connected independently', function () {
    [$owner, $store, $dispatcher] = boardWorkspace();

    DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
    // Ozon deliberately left unconnected (no row at all) for this test.

    $this->actingAs($dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ozon_connected', false)
            ->where('sendit_connected', true));
});

it('surfaces a verified Sendit shipment on the board with its own provider code and tracking number', function () {
    [$owner, $store, $dispatcher] = boardWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => ['default_pickup_district_id' => '1'], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Karim', 'customer_phone' => '0644556677',
        'confirmed_shipping_address' => '2 Rue Casablanca', 'shipping_city_id' => $city->id, 'total' => 200,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_board'], 200),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-BOARD-1']], 200),
    ]);
    app(SenditShipmentService::class)->send($order, $connection, [], $dispatcher);

    $this->actingAs($dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.id', $order->id)
            ->where('orders.0.shipment.provider.code', 'sendit')
            ->where('orders.0.shipment.tracking_number', 'SND-BOARD-1')
            ->where('orders.0.ozon_unverified', null)
            ->where('stats.in_flight', 1));
});

it('never confuses a Sendit shipment for the Ozon-only ozon_unverified banner', function () {
    // A Sendit shipment never reaches STATUS_PROVIDER_UNVERIFIED (Sendit has
    // no verification step) — ozon_unverified must stay null for it even
    // though the underlying query is no longer filtered to provider_code='ozon'.
    [$owner, $store, $dispatcher] = boardWorkspace();

    $city = City::create(['country_code' => 'MA', 'code' => 'RAB', 'name' => 'Rabat', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'sendit', 'provider_city_id' => '7', 'city_name' => 'Rabat']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => ['default_pickup_district_id' => '1'], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Nadia', 'customer_phone' => '0655667788',
        'confirmed_shipping_address' => '4 Rue Rabat', 'shipping_city_id' => $city->id, 'total' => 150,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 150, 'line_total' => 150]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_board2'], 200),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-BOARD-2']], 200),
    ]);
    app(SenditShipmentService::class)->send($order, $connection, [], $dispatcher);

    $this->actingAs($dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.ozon_unverified', null));
});
