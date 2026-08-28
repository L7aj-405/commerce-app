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
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| default_parcel_stock="0" must survive to the payload as "0" — never
| collapse to Ozon's "1" (stock parcel) just because "0" is falsy in PHP.
|--------------------------------------------------------------------------
*/

function stockModeTestDispatcher(Store $store): User
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
    $this->dispatcher = stockModeTestDispatcher($this->store);

    $this->city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $this->city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);
});

function stockModeConnection(Store $store, array $settings = []): DeliveryConnection
{
    return DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'super-secret-key'],
        'settings' => $settings, 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
}

/** A real local Product matching the SKU, so the order line resolves instead of tripping the "unmapped stock line" confirmation guard. */
function stockModeProduct(Store $store, string $sku): \App\Models\Product
{
    return \App\Models\Product::create([
        'store_id' => $store->id, 'name' => 'Widget', 'sku' => $sku,
        'type' => 'simple', 'status' => 'active', 'price' => 125,
    ]);
}

function stockModeOrder(Store $store, City $city, User $owner, ?array $items = null): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Test', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => $items ?? [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return $order;
}

// ---------------------------------------------------------------------
// Unit-level: OzonShipmentService::resolveParcelStock()
// ---------------------------------------------------------------------

it('resolveParcelStock returns "0" when default_parcel_stock is explicitly saved as "0"', function () {
    expect(OzonShipmentService::resolveParcelStock(['default_parcel_stock' => '0']))->toBe('0');
});

it('resolveParcelStock does not treat "0" as empty/falsy', function () {
    $settings = ['default_parcel_stock' => '0'];

    expect(array_key_exists('default_parcel_stock', $settings))->toBeTrue()
        ->and(OzonShipmentService::resolveParcelStock($settings))->not->toBe('1');
});

it('resolveParcelStock defaults to "0" when the setting is entirely missing', function () {
    expect(OzonShipmentService::resolveParcelStock([]))->toBe('0');
});

it('resolveParcelStock returns "1" only when explicitly configured', function () {
    expect(OzonShipmentService::resolveParcelStock(['default_parcel_stock' => '1']))->toBe('1');
});

it('resolveParcelStock treats an explicitly-empty string the same as unconfigured', function () {
    expect(OzonShipmentService::resolveParcelStock(['default_parcel_stock' => '']))->toBe('0');
});

// ---------------------------------------------------------------------
// End-to-end: Send to Ozon
// ---------------------------------------------------------------------

it('sends parcel-stock="0" end-to-end when the connection has default_parcel_stock="0" saved', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '0']);
    $order = stockModeOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === '0');

    expect(Shipment::where('shippable_id', $order->id)->firstOrFail()->tracking_number)->toBe('OZE1');
});

it('defaults to parcel-stock="0" end-to-end when no default_parcel_stock is configured at all', function () {
    $connection = stockModeConnection($this->store, []);
    $order = stockModeOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE2'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === '0');
});

it('sends products JSON built from order line SKUs when default_parcel_stock="1"', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '1']);
    stockModeProduct($this->store, 'SKU-1');
    $order = stockModeOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Widget', 'sku' => 'SKU-1', 'quantity' => 2, 'unit_price' => 125, 'line_total' => 250],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE3'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertSessionHasNoErrors();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        expect($request['parcel-stock'])->toBe('1');
        $products = json_decode((string) $request['products'], true);
        expect($products)->toBe([['ref' => 'SKU-1', 'qnty' => 2]]);

        return true;
    });
});

it('blocks before calling Ozon when default_parcel_stock="1" and the order has no product SKUs', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '1']);
    // No 'sku' key on the line at all.
    $order = stockModeOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Custom service', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250],
    ]);

    Http::fake();

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Ozon stock parcels require product details.');

    Http::assertNothingSent();
    expect(Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('sends products JSON even when default_parcel_stock="0", whenever SKUs are available', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '0']);
    stockModeProduct($this->store, 'SKU-9');
    $order = stockModeOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Widget', 'sku' => 'SKU-9', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE4'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        expect($request['parcel-stock'])->toBe('0');
        $products = json_decode((string) $request['products'], true);
        expect($products)->toBe([['ref' => 'SKU-9', 'qnty' => 1]]);

        return true;
    });
});

it('does not block on missing products when default_parcel_stock="0"', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '0']);
    $order = stockModeOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Custom service', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE5'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');
});

it('includes parcel-stock/parcel-price/parcel-city/products in the debug details on an Ozon rejection', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '0']);
    stockModeProduct($this->store, 'SKU-1');
    $order = stockModeOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Widget', 'sku' => 'SKU-1', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Products data required for stock parcels'],
    ], 200)]);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Ozon refused parcel: Products data required for stock parcels');

    $response->assertSessionHas('shipment_issue', function ($issue) {
        expect($issue['parcel_stock_sent'])->toBe('0')
            ->and($issue['parcel_price_sent'])->toBe('250')
            ->and($issue['parcel_city_sent'])->toBe('17')
            ->and($issue['has_products'])->toBeTrue()
            ->and($issue['products_count'])->toBe(1)
            ->and($issue['provider_message'])->toBe('Products data required for stock parcels');

        $flat = json_encode($issue);
        expect($flat)->not->toContain('super-secret-key')->not->toContain('CUST123');

        return true;
    });
});

it('never logs or exposes the api_key when reporting a parcel-stock related rejection', function () {
    $connection = stockModeConnection($this->store, ['default_parcel_stock' => '0']);
    $order = stockModeOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Products data required for stock parcels'],
    ], 200)]);
    Log::spy();

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        expect($message . json_encode($context))->not->toContain('super-secret-key');

        return true;
    });
});
