<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Delivery\ShipmentTrackingService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Ozon tracking sync — normalizes status, logs events, closes the order out
| through the EXISTING DispatchService (never a parallel status writer).
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
        'created_by' => $this->owner->id,
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

    // Http::fake only ever matches the FIRST registered rule for a URL
    // pattern, so a later Http::fake() call in a test body can never override
    // this one — key the tracking number off the receiver name instead of
    // re-faking, so every test (including the multi-order bulk one) gets a
    // distinct, deterministic tracking number for free.
    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => function ($request) {
            return Http::response(['TRACKING-NUMBER' => $request['parcel-receiver'] === 'Amine' ? 'OZE2' : 'OZE1'], 200);
        },
        // Registered here, alongside add-parcel, so send() below (which runs
        // its own parcel-info verification call immediately) sees a
        // confirming response and produces a VERIFIED shipment — every test
        // in this file assumes $this->shipment is already dispatched
        // (order_shipment_id set) before it registers its own /tracking fake.
        ...ozonVerifiedFakes(),
    ]);
    $this->shipment = app(OzonShipmentService::class)->send($this->order, $this->connection, [], $this->dispatcher);
});

it('creates a shipment event only when the tracking status actually changes', function () {
    Http::fake(['api.ozonexpress.ma/*/tracking' => Http::response(['status' => 'En transit'], 200)]);

    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);
    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1);

    // Same status again — no new event.
    app(ShipmentTrackingService::class)->refresh($this->shipment->fresh(), $this->dispatcher);
    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1);
});

it('marks delivered and advances the order through the existing OrderWorkflowService', function () {
    Http::fake(['api.ozonexpress.ma/*/tracking' => Http::response(['status' => 'Livré'], 200)]);

    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Delivered);

    $orderShipment = OrderShipment::findOrFail($this->shipment->order_shipment_id);
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_DELIVERED);
});

it('routes a refused parcel to the return flow without restocking', function () {
    $countBefore = \App\Models\StockLedger::count();

    Http::fake(['api.ozonexpress.ma/*/tracking' => Http::response(['status' => 'Refusé'], 200)]);
    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_REFUSED)
        ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Returned)
        ->and(\App\Models\StockLedger::count())->toBe($countBefore); // inspection still decides stock

    $orderShipment = OrderShipment::findOrFail($this->shipment->order_shipment_id);
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_FAILED);
});

it('handles bulk tracking for multiple shipments in one call', function () {
    $city2 = City::create(['country_code' => 'MA', 'code' => 'RAB', 'name' => 'Rabat', 'is_active' => true]);
    $pc2 = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '5', 'city_name' => 'Rabat']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city2->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $pc2->id]);

    $order2 = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Amine', 'customer_phone' => '0622334455',
        'confirmed_shipping_address' => '5 Avenue Rabat', 'shipping_city_id' => $city2->id, 'total' => 120,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 120, 'line_total' => 120]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order2 = $workflow->transition($order2, $s, $this->owner);
    }
    $shipment2 = app(OzonShipmentService::class)->send($order2, $this->connection, [], $this->dispatcher);

    Http::fake(['api.ozonexpress.ma/*/tracking' => Http::response([
        'OZE1' => ['status' => 'En transit'],
        'OZE2' => ['status' => 'Livré'],
    ], 200)]);

    $result = app(ShipmentTrackingService::class)->refreshBulk(
        Shipment::whereIn('id', [$this->shipment->id, $shipment2->id])->get(),
        $this->dispatcher,
    );

    expect($result['updated'])->toBe(2)
        ->and($this->shipment->fresh()->status)->toBe(Shipment::STATUS_IN_TRANSIT)
        ->and($shipment2->fresh()->status)->toBe(Shipment::STATUS_DELIVERED);
});

it('never lets tracking updates cross store tenancy', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->post("/dashboard/delivery-shipments/{$this->shipment->id}/refresh-tracking")
        ->assertNotFound();
});
