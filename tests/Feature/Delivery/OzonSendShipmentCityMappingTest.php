<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Send to Ozon must resolve a mapped city even when the order's own
| shipping_city_id is null and only raw platform text (differently cased/
| accented) is available — this is the real-world "Béni Mellal" vs
| "beni mellal" bug.
|--------------------------------------------------------------------------
*/

function cityMappingTestDispatcher(Store $store): User
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
    $this->dispatcher = cityMappingTestDispatcher($this->store);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->beniMellal = City::where('country_code', 'MA')->where('name', 'Béni Mellal')->firstOrFail();
    $this->ozonBeniMellal = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon',
        'provider_city_id' => '10', 'city_name' => 'Beni Mellal',
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $this->beniMellal->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $this->ozonBeniMellal->id,
    ]);
});

function readyOrderWithRawCity(Store $store, User $owner, ?string $cityId, string $rawCity): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Test',
        'shipping_city_id' => $cityId,
        'source_platform' => 'shopify',
        'platform_data' => ['shipping_address' => ['city' => $rawCity]],
        'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return $order;
}

it('sends to Ozon successfully when the order has no shipping_city_id but its raw platform city text matches a mapped internal city', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)] + ozonVerifiedFakes());

    $order = readyOrderWithRawCity($this->store, $this->owner, null, 'beni mellal');

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $shipment = Shipment::where('shippable_id', $order->id)->firstOrFail();

    expect($shipment->tracking_number)->toBe('OZE1')
        ->and($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        expect($request->url())->toContain('/add-parcel')
            ->and($request['parcel-city'])->toBe('10');

        return true;
    });
});

it('uses the confirmed shipping_city_id mapping when present, without needing raw text at all', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE2'], 200)] + ozonVerifiedFakes());

    $order = readyOrderWithRawCity($this->store, $this->owner, $this->beniMellal->id, 'this text is ignored once shipping_city_id resolves');

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request) => ($request['parcel-city'] ?? null) === '10');
});

it('blocks with a clear, actionable error when the city is truly unmapped', function () {
    $order = readyOrderWithRawCity($this->store, $this->owner, null, 'Zzznotarealcityname');

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect();

    $response->assertSessionHas('error', fn ($message) => str_contains($message, 'Zzznotarealcityname'));

    expect(Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('flashes a suggested-match hint alongside the error for an unrecognized city', function () {
    // "beni malal" is close enough to "Béni Mellal" to earn a fuzzy suggestion.
    $order = readyOrderWithRawCity($this->store, $this->owner, null, 'beni malal');

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('city_issue', function ($issue) {
            return $issue['raw_city'] === 'beni malal';
        });
});

it('does not resolve using a mapping that belongs to a different store', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    DeliveryConnection::create([
        'store_id' => $otherStore->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST999', 'api_key' => 'secret2'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    // No mapping on $otherStore, so an order there must fail to resolve
    // even though $this->store has "beni mellal" mapped.
    $otherDispatcher = cityMappingTestDispatcher($otherStore);

    $order = readyOrderWithRawCity($otherStore, $otherOwner, null, 'beni mellal');

    $this->actingAs($otherDispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});
