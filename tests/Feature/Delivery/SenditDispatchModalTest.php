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
| Dispatch modal — Integrated Provider tab, Sendit. Sending from the modal
| hits the EXACT SAME action as the order card's quick "Send to Sendit"
| button (POST /dashboard/delivery-shipments/orders/{order}/sendit) — there
| is only ever one Sendit-send code path.
|--------------------------------------------------------------------------
*/

function sdmWorkspace(): array
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

    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'super-secret-sendit-key'],
        'settings' => ['default_pickup_district_id' => '46'],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Test', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return [$owner, $store, $dispatcher, $connection, $order];
}

it('sends via the Integrated Provider tab\'s action, stores the provider shipment, and moves the order to In flight', function () {
    [, $store, $dispatcher, , $order] = sdmWorkspace();

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok'], 200),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-MODAL-1']], 200),
    ]);

    $response = $this->actingAs($dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/sendit")
        ->assertRedirect();

    expect($response->getSession()->get('success'))->toBe('Sendit shipment created. Code: SND-MODAL-1');

    $shipment = Shipment::where('store_id', $store->id)->where('shippable_id', $order->id)->where('provider_code', 'sendit')->firstOrFail();
    expect($shipment->tracking_number)->toBe('SND-MODAL-1')
        ->and($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();

    $orderShipment = OrderShipment::findOrFail($shipment->order_shipment_id);
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_DISPATCHED)
        ->and($orderShipment->carrier_name)->toBe('Sendit');

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.shipment.provider.code', 'sendit')
            ->where('orders.0.dispatch_readiness', null));
});

it('shows the exact Sendit rejection message and safe debug details, never the public/secret key', function () {
    [, , $dispatcher, , $order] = sdmWorkspace();

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok'], 200),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => false, 'message' => 'Invalid phone number'], 200),
    ]);

    $response = $this->actingAs($dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/sendit")
        ->assertRedirect()
        ->assertSessionHas('error', 'Invalid phone number')
        ->assertSessionHas('shipment_issue');

    $debug = $response->getSession()->get('shipment_issue');
    $flat = json_encode($debug);

    expect($flat)->not->toContain('super-secret-sendit-key')
        ->not->toContain('PUB1')
        ->and($debug)->toHaveKey('sent_district_id')
        ->and($debug)->toHaveKey('sent_pickup_district_id')
        ->and($debug['sent_district_id'])->toBe('12')
        ->and($debug['sent_pickup_district_id'])->toBe('46')
        ->and($debug)->toHaveKey('has_required_fields');

    expect(Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('does not let a member of another store use this order\'s Sendit connection through the modal action', function () {
    [, , , , $order] = sdmWorkspace();

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/sendit")
        ->assertNotFound();
});
