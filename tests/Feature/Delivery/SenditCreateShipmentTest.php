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
| Sending a packed order to Sendit
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
        'provider_code' => 'sendit',
        'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => ['default_pickup_district_id' => '1'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'region' => 'Casablanca-Settat', 'is_active' => true]);

    $this->order = Order::factory()->create([
        'store_id' => $this->store->id,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Sendit, Casablanca',
        'shipping_city_id' => $this->city->id,
        'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $this->order = $workflow->transition($this->order, $s, $this->owner);
    }
});

function senditLoginResponse(): array
{
    return ['app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_ship'], 200)];
}

it('blocks sending to sendit when the order city is not mapped', function () {
    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/sendit")
        ->assertRedirect();

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $this->order->id, 'provider_code' => 'sendit']);
});

it('blocks sending to sendit when no default pickup district is configured', function () {
    $this->connection->update(['settings' => []]);

    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/sendit")
        ->assertRedirect()
        ->assertSessionHas('error', 'Set a default pickup district for Sendit before sending orders.');

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $this->order->id, 'provider_code' => 'sendit']);
});

it('creates a Sendit delivery with the correct payload, mapped district_id, and stores the tracking code', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit',
        'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    Http::fake([
        ...senditLoginResponse(),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-0001']], 200),
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/sendit")
        ->assertRedirect();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/deliveries') || str_contains($request->url(), 'getlabels')) {
            return false;
        }

        expect($request['district_id'])->toBe('12')
            ->and($request['pickup_district_id'])->toBe('1')
            ->and($request['name'])->toBe('Sara')
            ->and($request['phone'])->toBe('0611223344')
            ->and($request['address'])->toBe('12 Rue Sendit, Casablanca')
            ->and((float) $request['amount'])->toBe(250.0)
            ->and($request['products_from_stock'])->toBe(0);

        return true;
    });

    $shipment = Shipment::where('shippable_id', $this->order->id)->where('provider_code', 'sendit')->firstOrFail();
    expect($shipment->tracking_number)->toBe('SND-0001')
        ->and($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();

    $orderShipment = OrderShipment::findOrFail($shipment->order_shipment_id);
    expect($orderShipment->carrier_type)->toBe('courier')
        ->and($orderShipment->carrier_name)->toBe('Sendit')
        ->and($orderShipment->tracking_number)->toBe('SND-0001');
});

it('shows the real Sendit rejection message when success=false', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit',
        'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    Http::fake([
        ...senditLoginResponse(),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => false, 'message' => 'Invalid phone number'], 200),
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/sendit")
        ->assertRedirect()
        ->assertSessionHas('error', 'Invalid phone number');

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $this->order->id, 'provider_code' => 'sendit']);
});

it('rejects sending an order that is not yet ready for delivery', function () {
    $notReady = Order::factory()->create([
        'store_id' => $this->store->id,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'X', 'customer_phone' => '0600000000',
        'confirmed_shipping_address' => 'addr', 'shipping_city_id' => $this->city->id,
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$notReady->id}/sendit")
        ->assertRedirect();

    $this->assertDatabaseMissing('shipments', ['shippable_id' => $notReady->id, 'provider_code' => 'sendit']);
});

it('does not let a member of another store send this order to sendit', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/sendit")
        ->assertNotFound();
});

it('does not disturb an existing active Ozon shipment for the same order', function () {
    $ozonConnection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'key'], 'settings' => [],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
    Shipment::create([
        'store_id' => $this->store->id, 'shippable_type' => Order::class, 'shippable_id' => $this->order->id,
        'delivery_connection_id' => $ozonConnection->id, 'provider_code' => 'ozon',
        'tracking_number' => 'OZE-EXISTING', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'receiver_name' => 'Sara', 'phone' => '0611223344', 'address' => 'addr',
    ]);

    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit',
        'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->city->id,
        'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id,
    ]);

    Http::fake([
        ...senditLoginResponse(),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-0002']], 200),
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/sendit")
        ->assertRedirect();

    // Both the pre-existing Ozon shipment row and the new Sendit one coexist.
    expect(Shipment::where('shippable_id', $this->order->id)->where('provider_code', 'ozon')->exists())->toBeTrue()
        ->and(Shipment::where('shippable_id', $this->order->id)->where('provider_code', 'sendit')->exists())->toBeTrue();
});
