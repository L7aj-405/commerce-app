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
| End-to-end "Send to Ozon" error surfacing: the real provider error (not a
| generic message) reaches the flash session, and shipment-creation
| failures carry safe debug detail distinct from city-mapping failures.
|--------------------------------------------------------------------------
*/

function uiErrorTestDispatcher(Store $store): User
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
    $this->dispatcher = uiErrorTestDispatcher($this->store);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'super-secret-key'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $this->order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Test', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $this->order = $workflow->transition($this->order, $s, $this->owner);
    }
});

it('shows the real Ozon provider error instead of a generic message when the parcel is rejected', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['error' => 'Phone number is invalid'], 422)]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Phone number is invalid');

    expect(Shipment::where('shippable_id', $this->order->id)->exists())->toBeFalse();
});

it('flashes safe shipment_issue debug detail (no api_key) when the response is non-JSON', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response('<html>Gateway error</html>', 502)]);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertRedirect();

    $response->assertSessionHas('shipment_issue', function ($issue) {
        $flat = json_encode($issue);

        expect($flat)->not->toContain('super-secret-key')
            ->and($flat)->not->toContain('CUST123');

        return isset($issue['http_status']) && $issue['http_status'] === 502;
    });
});

it('does not flash a shipment_issue for a city-mapping failure (they are distinct error types)', function () {
    $unmapped = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'X', 'customer_phone' => '0600000000',
        'confirmed_shipping_address' => 'addr', 'shipping_city_id' => null,
        'source_platform' => 'shopify', 'platform_data' => ['shipping_address' => ['city' => 'Zzznotreal']],
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $unmapped = $workflow->transition($unmapped, $s, $this->owner);
    }

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$unmapped->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('city_issue')
        ->assertSessionMissing('shipment_issue');
});

it('sends the parcel with parcel-stock defaulted to 0 and succeeds when Ozon accepts it', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$this->order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === '0');

    expect(Shipment::where('shippable_id', $this->order->id)->firstOrFail()->tracking_number)->toBe('OZE1');
});

it('does not call Ozon at all when the city is unmapped, regardless of shipment-creation logic', function () {
    $unmapped = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'X', 'customer_phone' => '0600000000',
        'confirmed_shipping_address' => 'addr', 'shipping_city_id' => null,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $unmapped = $workflow->transition($unmapped, $s, $this->owner);
    }

    Http::fake();

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$unmapped->id}/ozon");

    Http::assertNothingSent();
});
