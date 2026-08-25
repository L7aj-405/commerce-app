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
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sending a packed order to Ozon Express
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
        'store_id' => $this->store->id,
        'provider_code' => 'ozon',
        'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'region' => 'Casablanca-Settat', 'is_active' => true]);

    $this->order = Order::factory()->create([
        'store_id' => $this->store->id,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Ozon, Casablanca',
        'shipping_city_id' => $this->city->id,
        'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $this->order = $workflow->transition($this->order, $s, $this->owner);
    }
});

it('blocks sending to ozon when the order city is not mapped', function () {
    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertRedirect();

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $this->order->id]);
});

it('creates an ozon parcel with the correct form-data fields and stores the tracking number', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon',
        'provider_city_id' => '17', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    Http::fake(['api.ozonexpress.ma/*' => Http::response([
        'TRACKING-NUMBER' => 'OZE123456789', 'RECEIVER' => 'Sara', 'CITY_ID' => '17',
    ], 200)]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertRedirect();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        expect($request->url())->toContain('/add-parcel')
            ->and($request['parcel-receiver'])->toBe('Sara')
            ->and($request['parcel-phone'])->toBe('0611223344')
            ->and($request['parcel-city'])->toBe('17')
            ->and($request['parcel-address'])->toBe('12 Rue Ozon, Casablanca')
            ->and((float) $request['parcel-price'])->toBe(250.0);

        return true;
    });

    $shipment = Shipment::where('shippable_id', $this->order->id)->firstOrFail();
    expect($shipment->tracking_number)->toBe('OZE123456789')
        ->and($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();

    $orderShipment = OrderShipment::findOrFail($shipment->order_shipment_id);
    expect($orderShipment->carrier_type)->toBe('courier')
        ->and($orderShipment->carrier_name)->toBe('Ozon Express')
        ->and($orderShipment->tracking_number)->toBe('OZE123456789');
});

it('sends parcel-price as a clean integer string, not a decimal, for a non-round order total', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon',
        'provider_city_id' => '17', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    $order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Ozon, Casablanca',
        'shipping_city_id' => $this->city->id, 'total' => 93.99,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 93.99, 'line_total' => 93.99]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $this->owner);
    }

    Http::fake(['api.ozonexpress.ma/*' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-price'] ?? null) === '94');
});

it('shows the real Ozon business-error message (HTTP 200, ADD-PARCEL.RESULT=ERROR) instead of a generic tracking-number error', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon',
        'provider_city_id' => '17', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    Http::fake(['api.ozonexpress.ma/*' => Http::response([
        'ADD-PARCEL' => [
            'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
            'RESULT' => 'ERROR',
            'MESSAGE' => 'Price without commas',
        ],
    ], 200)]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Ozon refused parcel: Price without commas');

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $this->order->id]);
});

it('rejects sending an order that is not yet ready for delivery', function () {
    $notReady = Order::factory()->create([
        'store_id' => $this->store->id,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'X', 'customer_phone' => '0600000000',
        'confirmed_shipping_address' => 'addr', 'shipping_city_id' => $this->city->id,
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$notReady->id}/ozon")
        ->assertRedirect();

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $notReady->id]);
});

it('does not let a member of another store send this order to ozon', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertNotFound();
});
