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
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Strict post-create verification: add-parcel returning HTTP 200 + a
| tracking number is NOT trusted alone — Ozon has been observed to hand
| back a tracking number for a parcel its own dashboard search cannot
| find. A follow-up parcel-info (falling back to tracking) call must
| independently confirm the same tracking number before this project
| treats the shipment as real and moves the order out of "awaiting carrier".
|--------------------------------------------------------------------------
*/

function verificationDispatcher(Store $store): User
{
    $role = $store->roles()->where('name', 'Dispatcher')->firstOrFail();
    $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $member;
}

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();
    $this->dispatcher = verificationDispatcher($this->store);

    $this->city = City::create(['country_code' => 'MA', 'code' => 'TAN', 'name' => 'Tanger', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '3', 'city_name' => 'Tanger']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $this->city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST-V', 'api_key' => 'verification-secret'],
        'settings' => ['default_parcel_stock' => '0'], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
});

function verificationOrder(Store $store, City $city, User $owner): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Fatima', 'customer_phone' => '0699887766',
        'confirmed_shipping_address' => '4 Rue Tanger', 'shipping_city_id' => $city->id, 'total' => 180,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 180, 'line_total' => 180]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return $order;
}

// ---------------------------------------------------------------------
// 1. add-parcel success + parcel-info success => verified
// ---------------------------------------------------------------------

it('marks a shipment verified when add-parcel succeeds and parcel-info confirms it', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['ADD-PARCEL' => [
            'RESULT' => 'SUCCESS', 'MESSAGE' => 'New Parcel Added',
            'NEW-PARCEL' => ['TRACKING-NUMBER' => 'BML001'],
        ]], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['PARCEL-INFO' => ['RESULT' => 'SUCCESS', 'TRACKING-NUMBER' => 'BML001']], 200),
    ]);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('success', 'Ozon parcel created and verified. Tracking: BML001');

    $response->assertSessionMissing('warning');

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->tracking_number)->toBe('BML001')
        ->and($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();
});

// ---------------------------------------------------------------------
// 2. add-parcel success + parcel-info AND tracking not found => stays awaiting carrier
// ---------------------------------------------------------------------

it('keeps the order in awaiting carrier when neither parcel-info nor tracking can confirm the parcel', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['ADD-PARCEL' => [
            'RESULT' => 'SUCCESS', 'MESSAGE' => 'New Parcel Added',
            'NEW-PARCEL' => ['TRACKING-NUMBER' => 'BML002'],
        ]], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['PARCEL-INFO' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel not found']], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response(['TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel not found']], 200),
    ]);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('warning', 'Ozon returned a tracking number, but the parcel could not be verified in Ozon. Do not hand this parcel to carrier yet.');

    $response->assertSessionMissing('success');

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->status)->toBe(Shipment::STATUS_PROVIDER_UNVERIFIED)
        ->and($shipment->order_shipment_id)->toBeNull();

    // The order never got bridged into the internal dispatch board — still
    // exactly where it was before Send to Ozon was clicked.
    expect(OrderShipment::where('shippable_id', $order->id)->exists())->toBeFalse()
        ->and($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForDelivery);
});

// ---------------------------------------------------------------------
// 3. unverified create stores tracking number + raw payload for diagnostics
// ---------------------------------------------------------------------

it('stores the tracking number and raw payloads on an unverified shipment for diagnostics', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['ADD-PARCEL' => [
            'RESULT' => 'SUCCESS', 'MESSAGE' => 'New Parcel Added', 'TRACKING-NUMBER' => 'BML003',
        ]], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response([], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response([], 200),
    ]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->tracking_number)->toBe('BML003')
        ->and($shipment->raw_payload['add_parcel'])->not->toBeNull()
        ->and($shipment->raw_payload['add_parcel_result'])->toBe('SUCCESS')
        ->and($shipment->raw_payload['add_parcel_message'])->toBe('New Parcel Added')
        ->and($shipment->raw_payload['verification']['verified'])->toBeFalse();

    $debug = OzonShipmentService::verificationDebug($shipment);
    expect($debug['tracking_number_returned'])->toBe('BML003')
        ->and($debug['verification_status'])->toBe('unverified');
});

// ---------------------------------------------------------------------
// 4. unverified create does not move the order to "In flight"
// ---------------------------------------------------------------------

it('does not call DispatchService::assign for an unverified shipment', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BML004'], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response([], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response([], 200),
    ]);

    app(OzonShipmentService::class)->send($order, $this->connection, [], $this->dispatcher);

    expect(OrderShipment::where('shippable_id', $order->id)->where('shippable_type', Order::class)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// 5. retry verification can mark a shipment verified later
// ---------------------------------------------------------------------

it('promotes a shipment to verified and dispatches it on a successful retry', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    // Http::fake only ever matches the FIRST registered rule for a URL
    // pattern within one test (later Http::fake() calls can never override
    // it — see OzonTrackingTest.php's own note on this) — so the "first
    // attempt unconfirmed, retry confirmed" transition has to live inside
    // ONE stub, keyed off how many times it's been called.
    $parcelInfoCalls = 0;
    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BML005'], 200),
        'api.ozonexpress.ma/*/parcel-info' => function () use (&$parcelInfoCalls) {
            $parcelInfoCalls++;

            return $parcelInfoCalls === 1
                ? Http::response([], 200)
                : Http::response(['PARCEL-INFO' => ['RESULT' => 'SUCCESS', 'TRACKING-NUMBER' => 'BML005']], 200);
        },
        'api.ozonexpress.ma/*/tracking' => Http::response([], 200),
    ]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->status)->toBe(Shipment::STATUS_PROVIDER_UNVERIFIED);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/{$shipment->id}/retry-verification")
        ->assertRedirect()
        ->assertSessionHas('success', 'Ozon parcel created and verified. Tracking: BML005');

    $response->assertSessionMissing('warning');

    $shipment->refresh();
    expect($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();

    $orderShipment = OrderShipment::findOrFail($shipment->order_shipment_id);
    expect($orderShipment->carrier_name)->toBe('Ozon Express')
        ->and($orderShipment->tracking_number)->toBe('BML005');
});

it('retry verification stays unverified and keeps the order awaiting carrier when still unconfirmed', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BML006'], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response([], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response([], 200),
    ]);
    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");
    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();

    Http::fake([
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['PARCEL-INFO' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel not found']], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response(['TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel not found']], 200),
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/{$shipment->id}/retry-verification")
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect($shipment->fresh()->status)->toBe(Shipment::STATUS_PROVIDER_UNVERIFIED)
        ->and($shipment->fresh()->order_shipment_id)->toBeNull();
});

// ---------------------------------------------------------------------
// 6. parcel-info failure falls back to tracking
// ---------------------------------------------------------------------

it('falls back to tracking when parcel-info fails, and verifies from tracking alone', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BML007'], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['error' => 'Service unavailable'], 500),
        'api.ozonexpress.ma/*/tracking' => Http::response(['TRACKING' => ['RESULT' => 'SUCCESS', 'STATUS' => 'En attente']], 200),
    ]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertSessionHas('success');

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER);

    $debug = OzonShipmentService::verificationDebug($shipment);
    expect($debug['parcel_info_http_status'])->toBe(500)
        ->and($debug['tracking_http_status'])->toBe(200)
        ->and($debug['verification_status'])->toBe('verified');
});

// ---------------------------------------------------------------------
// 7. a verified shipment moves the order/dispatch through the existing DispatchService
// ---------------------------------------------------------------------

it('bridges a verified shipment into the existing order_shipments dispatch board', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BML008'], 200),
        ...ozonVerifiedFakes(),
    ]);

    $shipment = app(OzonShipmentService::class)->send($order, $this->connection, [], $this->dispatcher);

    expect($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->order_shipment_id)->not->toBeNull();

    $orderShipment = OrderShipment::findOrFail($shipment->order_shipment_id);
    expect($orderShipment->carrier_type)->toBe('courier')
        ->and($orderShipment->carrier_name)->toBe('Ozon Express')
        ->and($orderShipment->tracking_number)->toBe('BML008')
        ->and($orderShipment->shippable_id)->toBe($order->id);
});

// ---------------------------------------------------------------------
// 8. API key is never exposed
// ---------------------------------------------------------------------

it('never exposes the api_key in verification debug details or the unverified flash', function () {
    $order = verificationOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BML009'], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['PARCEL-INFO' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel not found']], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response(['TRACKING' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Parcel not found']], 200),
    ]);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect();

    $response->assertSessionHas('shipment_verification', function ($debug) {
        $flat = json_encode($debug);
        expect($flat)->not->toContain('verification-secret')->not->toContain('CUST-V')
            ->not->toContain('api_key')->not->toContain('api.ozonexpress.ma');

        return true;
    });

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();
    $flatRaw = json_encode($shipment->raw_payload);
    expect($flatRaw)->not->toContain('verification-secret')->not->toContain('CUST-V');
});
