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
| Dispatch modal — Integrated Provider tab, Ozon. Sending from the modal
| hits the EXACT SAME action as the order card's quick "Send to Ozon"
| button (POST /dashboard/delivery-shipments/orders/{order}/ozon) — there
| is only ever one Ozon-send code path.
|--------------------------------------------------------------------------
*/

function odmWorkspace(): array
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
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'super-secret-ozon-key'], 'settings' => [],
        'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

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
    [, $store, $dispatcher, , $order] = odmWorkspace();

    Http::fake(['api.ozonexpress.ma/*' => Http::response(['TRACKING-NUMBER' => 'OZE-MODAL-1'], 200)]);

    $this->actingAs($dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect();

    $shipment = Shipment::where('store_id', $store->id)->where('shippable_id', $order->id)->where('provider_code', 'ozon')->firstOrFail();
    expect($shipment->tracking_number)->toBe('OZE-MODAL-1')
        ->and($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();

    $orderShipment = OrderShipment::findOrFail($shipment->order_shipment_id);
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_DISPATCHED)
        ->and($orderShipment->carrier_name)->toBe('Ozon Express');

    // The board now reports this order as in-flight (no shipment===null),
    // and dispatch_readiness is no longer computed for it — matches the
    // "awaiting carrier" -> "in flight" transition the modal promises.
    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.shipment.provider.code', 'ozon')
            ->where('orders.0.dispatch_readiness', null));
});

it('shows the exact Ozon rejection message and safe debug details, never the api_key or customer_id', function () {
    [, , $dispatcher, , $order] = odmWorkspace();

    Http::fake(['api.ozonexpress.ma/*' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Price without commas'],
    ], 200)]);

    $response = $this->actingAs($dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Ozon refused parcel: Price without commas')
        ->assertSessionHas('shipment_issue');

    $debug = $response->getSession()->get('shipment_issue');
    $flat = json_encode($debug);

    expect($flat)->not->toContain('super-secret-ozon-key')
        ->not->toContain('CUST1')
        // Ozon's debug shape — 'sent_district_id' is Sendit's own key, never Ozon's.
        ->and($debug)->not->toHaveKey('sent_district_id');

    expect(Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('does not let a member of another store use this order\'s Ozon connection through the modal action', function () {
    [, , , , $order] = odmWorkspace();

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertNotFound();
});
