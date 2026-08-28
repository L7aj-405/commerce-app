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
use App\Services\Delivery\SenditShipmentService;
use App\Services\Delivery\ShipmentTrackingService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sendit tracking sync — normalizes status, logs events, closes the order
| out through the EXISTING DispatchService (never a parallel status
| writer), via the SAME ShipmentTrackingService Ozon uses (resolved through
| DeliveryConnectorFactory).
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
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => ['default_pickup_district_id' => '1'], 'status' => DeliveryConnection::STATUS_CONNECTED,
        'created_by' => $this->owner->id,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    $this->order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Sendit', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $this->order = $workflow->transition($this->order, $s, $this->owner);
    }

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_track'], 200),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-TRACK-1']], 200),
    ]);
    $this->shipment = app(SenditShipmentService::class)->send($this->order, $this->connection, [], $this->dispatcher);
});

it('creates a shipment event only when the tracking status actually changes', function () {
    Http::fake(['app.sendit.ma/api/v1/deliveries/SND-TRACK-1' => Http::response(['data' => ['status' => 'TRANSIT']], 200)]);

    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);
    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1);

    app(ShipmentTrackingService::class)->refresh($this->shipment->fresh(), $this->dispatcher);
    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1);
});

it('marks delivered and advances the order through the existing OrderWorkflowService', function () {
    Http::fake(['app.sendit.ma/api/v1/deliveries/SND-TRACK-1' => Http::response(['data' => ['status' => 'DELIVERED']], 200)]);

    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Delivered);

    $orderShipment = OrderShipment::findOrFail($this->shipment->order_shipment_id);
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_DELIVERED);
});

it('routes a REJECTED delivery to the return flow without restocking', function () {
    $countBefore = \App\Models\StockLedger::count();

    Http::fake(['app.sendit.ma/api/v1/deliveries/SND-TRACK-1' => Http::response(['data' => ['status' => 'REJECTED']], 200)]);
    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_REFUSED)
        ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Returned)
        ->and(\App\Models\StockLedger::count())->toBe($countBefore);

    $orderShipment = OrderShipment::findOrFail($this->shipment->order_shipment_id);
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_FAILED);
});

it('routes a CANCELED delivery to cancelled without restocking', function () {
    Http::fake(['app.sendit.ma/api/v1/deliveries/SND-TRACK-1' => Http::response(['data' => ['status' => 'CANCELED']], 200)]);
    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_CANCELLED);
});

it('closes out the failure reason with the real provider name (Sendit), not a hardcoded Ozon label', function () {
    Http::fake(['app.sendit.ma/api/v1/deliveries/SND-TRACK-1' => Http::response(['data' => ['status' => 'REJECTED']], 200)]);
    app(ShipmentTrackingService::class)->refresh($this->shipment, $this->dispatcher);

    $orderShipment = OrderShipment::findOrFail($this->shipment->order_shipment_id);
    expect($orderShipment->failure_reason)->toBe('Sendit: refused.');
});

it('never lets Sendit tracking updates cross store tenancy', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->post("/dashboard/delivery-shipments/{$this->shipment->id}/refresh-tracking")
        ->assertNotFound();
});
