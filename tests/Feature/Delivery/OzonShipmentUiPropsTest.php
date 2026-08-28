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
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Ozon shipment data reaching the React/Inertia pages — additive only, the
| existing Dispatch board / order-detail props must still be present.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $role = $this->store->roles()->where('name', 'Dispatcher')->firstOrFail();
    $this->dispatcher = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $this->dispatcher->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $this->order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Ozon', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $this->order = $workflow->transition($this->order, $s, $this->owner);
    }
});

it('flags ozon_connected on the dispatch board without touching existing keys', function () {
    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('ozon_connected', true)
            ->has('orders')
            ->has('couriers')
            ->has('manifests')
            ->has('stats'));
});

it('adds a provider block to the dispatch board row once sent to ozon', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)] + ozonVerifiedFakes());
    app(OzonShipmentService::class)->send($this->order, $this->connection, [], $this->dispatcher);

    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.id', $this->order->id)
            ->where('orders.0.shipment.carrier_label', 'Ozon Express')
            ->where('orders.0.shipment.tracking_number', 'OZE1')
            ->where('orders.0.shipment.provider.code', 'ozon')
            ->where('orders.0.shipment.provider.tracking_number', 'OZE1'));
});

it('adds a shipment prop to the online order detail page', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)] + ozonVerifiedFakes());
    app(OzonShipmentService::class)->send($this->order, $this->connection, [], $this->dispatcher);

    $this->actingAs($this->dispatcher)
        ->get("/dashboard/orders/online/{$this->order->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('order')
            ->has('shipment')
            ->where('shipment.provider', 'ozon')
            ->where('shipment.tracking_number', 'OZE1'));
});

it('returns a null shipment prop for an order never sent to any provider', function () {
    $this->actingAs($this->dispatcher)
        ->get("/dashboard/orders/online/{$this->order->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('shipment', null));
});
