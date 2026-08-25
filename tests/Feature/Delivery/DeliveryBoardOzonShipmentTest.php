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
| Dispatch board visibility for Ozon shipments — a shipment stuck at
| STATUS_PROVIDER_UNVERIFIED never gets an order_shipments row (see
| OzonShipmentService::send()), so DepartmentController::dispatch() must
| surface it a different way or it would silently disappear from the board.
|--------------------------------------------------------------------------
*/

function boardDispatcher(Store $store): User
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
    $this->dispatcher = boardDispatcher($this->store);

    $this->city = City::create(['country_code' => 'MA', 'code' => 'OUJ', 'name' => 'Oujda', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '8', 'city_name' => 'Oujda']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $this->city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST-B', 'api_key' => 'board-secret'],
        'settings' => ['default_parcel_stock' => '0'], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
});

function boardOrder(Store $store, City $city, User $owner): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Karim', 'customer_phone' => '0644556677',
        'confirmed_shipping_address' => '2 Rue Oujda', 'shipping_city_id' => $city->id, 'total' => 200,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return $order;
}

it('surfaces an unverified Ozon shipment on the dispatch board, still counted as awaiting carrier', function () {
    $order = boardOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BOARD1'], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response([], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response([], 200),
    ]);
    app(OzonShipmentService::class)->send($order, $this->connection, [], $this->dispatcher);

    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.id', $order->id)
            ->where('orders.0.shipment', null)
            ->where('orders.0.ozon_unverified.tracking_number', 'BOARD1')
            ->where('stats.awaiting', 1));
});

it('does not surface ozon_unverified for a verified shipment', function () {
    $order = boardOrder($this->store, $this->city, $this->owner);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BOARD2'], 200),
        ...ozonVerifiedFakes(),
    ]);
    app(OzonShipmentService::class)->send($order, $this->connection, [], $this->dispatcher);

    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.id', $order->id)
            ->where('orders.0.ozon_unverified', null)
            ->where('orders.0.shipment.provider.code', 'ozon')
            ->where('orders.0.shipment.tracking_number', 'BOARD2'));
});

it('does not surface ozon_unverified for an order never sent to any provider', function () {
    boardOrder($this->store, $this->city, $this->owner);

    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.ozon_unverified', null));
});

it('clears ozon_unverified once a retried verification succeeds', function () {
    $order = boardOrder($this->store, $this->city, $this->owner);

    $parcelInfoCalls = 0;
    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'BOARD3'], 200),
        'api.ozonexpress.ma/*/parcel-info' => function () use (&$parcelInfoCalls) {
            $parcelInfoCalls++;

            return $parcelInfoCalls === 1
                ? Http::response([], 200)
                : Http::response(['PARCEL-INFO' => ['RESULT' => 'SUCCESS', 'TRACKING-NUMBER' => 'BOARD3']], 200);
        },
        'api.ozonexpress.ma/*/tracking' => Http::response([], 200),
    ]);

    $shipment = app(OzonShipmentService::class)->send($order, $this->connection, [], $this->dispatcher);
    expect($shipment->status)->toBe(\App\Models\Shipment::STATUS_PROVIDER_UNVERIFIED);

    app(OzonShipmentService::class)->retryVerification($shipment, $this->dispatcher);

    $this->actingAs($this->dispatcher)
        ->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.ozon_unverified', null)
            ->where('orders.0.shipment.provider.tracking_number', 'BOARD3'));
});
