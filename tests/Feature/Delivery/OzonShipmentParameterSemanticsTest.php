<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Ozon's documented add-parcel parameter semantics (per Ozon's own docs):
|   parcel-stock:   1 = stock, 0 = ramassage
|   parcel-open:    1 = ouvrir le colis, 2 = ne pas ouvrir (default 1)
|   parcel-fragile: 1 = oui, 0 = non (default 0)
|   parcel-replace: 1 = oui, 0 = non (default 0)
|   products:       [{"ref": "...", "qnty": N}] — "qnty", NOT "qty".
|--------------------------------------------------------------------------
*/

function paramSemanticsDispatcher(Store $store): User
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
    $this->dispatcher = paramSemanticsDispatcher($this->store);

    $this->city = City::create(['country_code' => 'MA', 'code' => 'MEK', 'name' => 'Meknes', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '21', 'city_name' => 'Meknes']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $this->city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);
});

function paramSemanticsConnection(Store $store, array $settings = []): DeliveryConnection
{
    return DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUSTX', 'api_key' => 'super-secret'],
        'settings' => $settings, 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
}

function paramSemanticsOrder(Store $store, City $city, User $owner, ?array $items = null): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Nadia', 'customer_phone' => '0655667788',
        'confirmed_shipping_address' => '7 Rue Meknes', 'shipping_city_id' => $city->id, 'total' => 180,
        'items' => $items ?? [['name' => 'Item', 'quantity' => 1, 'unit_price' => 180, 'line_total' => 180]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return $order;
}

// ---------------------------------------------------------------------
// 1-2. parcel-stock semantics
// ---------------------------------------------------------------------

it('preserves and sends parcel-stock="1" (stock) when configured', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '1']);
    Product::create(['store_id' => $this->store->id, 'name' => 'Widget', 'sku' => 'SKU-STOCK', 'type' => 'simple', 'status' => 'active', 'price' => 180]);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Widget', 'sku' => 'SKU-STOCK', 'quantity' => 1, 'unit_price' => 180, 'line_total' => 180],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'PS1'], 200)]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === '1');
});

it('preserves and sends parcel-stock="0" (ramassage) when configured', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '0']);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'PS0'], 200)]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === '0');
});

// ---------------------------------------------------------------------
// 3-4. parcel-open semantics (1 = ouvrir, 2 = ne pas ouvrir)
// ---------------------------------------------------------------------

it('resolveParcelOpen sends "1" for ouvrir le colis', function () {
    expect(OzonShipmentService::resolveParcelOpen(['default_parcel_open' => '1']))->toBe('1');
});

it('resolveParcelOpen sends "2" for ne pas ouvrir le colis', function () {
    expect(OzonShipmentService::resolveParcelOpen(['default_parcel_open' => '2']))->toBe('2');
});

it('resolveParcelOpen defaults to "1" when unconfigured', function () {
    expect(OzonShipmentService::resolveParcelOpen([]))->toBe('1');
});

it('sends parcel-open="2" end-to-end when configured as ne pas ouvrir', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '0', 'default_parcel_open' => '2']);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'PO2'], 200)]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-open'] ?? null) === '2');
});

it('sends parcel-open="1" end-to-end by default when nothing is configured', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '0']);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'PO1'], 200)]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-open'] ?? null) === '1');
});

// ---------------------------------------------------------------------
// 5-6. fragile / replace booleans -> "1"/"0"
// ---------------------------------------------------------------------

it('resolveBooleanFlag sends "1" when fragile is checked', function () {
    expect(OzonShipmentService::resolveBooleanFlag(['default_fragile' => true], 'default_fragile'))->toBe('1');
});

it('resolveBooleanFlag sends "0" when fragile is unchecked', function () {
    expect(OzonShipmentService::resolveBooleanFlag(['default_fragile' => false], 'default_fragile'))->toBe('0');
});

it('resolveBooleanFlag sends "0" for replace when key is entirely absent', function () {
    expect(OzonShipmentService::resolveBooleanFlag([], 'default_replace'))->toBe('0');
});

it('sends parcel-fragile="1" and parcel-replace="1" end-to-end when both are checked', function () {
    $connection = paramSemanticsConnection($this->store, [
        'default_parcel_stock' => '0', 'default_fragile' => true, 'default_replace' => true,
    ]);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'FR1'], 200)]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-fragile'] ?? null) === '1' && ($request['parcel-replace'] ?? null) === '1');
});

it('sends parcel-fragile="0" and parcel-replace="0" end-to-end when both are unchecked', function () {
    $connection = paramSemanticsConnection($this->store, [
        'default_parcel_stock' => '0', 'default_fragile' => false, 'default_replace' => false,
    ]);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'FR0'], 200)]);

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(fn ($request) => ($request['parcel-fragile'] ?? null) === '0' && ($request['parcel-replace'] ?? null) === '0');
});

// ---------------------------------------------------------------------
// 7-8. products JSON: ref + qnty (never qty)
// ---------------------------------------------------------------------

it('sends products JSON with ref/qnty for a stock parcel', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '1']);
    Product::create(['store_id' => $this->store->id, 'name' => 'Lamp', 'sku' => 'LUME-15', 'type' => 'simple', 'status' => 'active', 'price' => 180]);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Lamp', 'sku' => 'LUME-15', 'quantity' => 1, 'unit_price' => 180, 'line_total' => 180],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'PRD1'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertSessionHasNoErrors();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        $products = json_decode((string) $request['products'], true);
        expect($products)->toBe([['ref' => 'LUME-15', 'qnty' => 1]]);

        return true;
    });
});

it('uses "qnty" as the quantity key in the products JSON, never "qty"', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '1']);
    Product::create(['store_id' => $this->store->id, 'name' => 'Lamp', 'sku' => 'LUME-16', 'type' => 'simple', 'status' => 'active', 'price' => 180]);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Lamp', 'sku' => 'LUME-16', 'quantity' => 3, 'unit_price' => 60, 'line_total' => 180],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'PRD2'], 200)] + ozonVerifiedFakes());

    $this->actingAs($this->dispatcher)->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon");

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        $products = json_decode((string) $request['products'], true);
        expect($products[0])->toHaveKey('qnty')
            ->and($products[0])->not->toHaveKey('qty')
            ->and($products[0]['qnty'])->toBe(3);

        return true;
    });
});

// ---------------------------------------------------------------------
// 9. stock parcel without products blocks before the API call
// ---------------------------------------------------------------------

it('blocks a stock parcel with no product details before calling Ozon', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '1']);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Custom service', 'quantity' => 1, 'unit_price' => 180, 'line_total' => 180],
    ]);

    Http::fake();

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Ozon stock parcels require product details.');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------
// 10. Ozon provider error is shown clearly
// ---------------------------------------------------------------------

it('shows the exact Ozon provider error message on rejection', function () {
    $connection = paramSemanticsConnection($this->store, ['default_parcel_stock' => '0']);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Products data required for stock parcels'],
    ], 200)]);

    $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect()
        ->assertSessionHas('error', 'Ozon refused parcel: Products data required for stock parcels');
});

it('includes parcel_open_sent, parcel_fragile_sent, parcel_replace_sent, and products_json_preview in debug details, never the api_key', function () {
    $connection = paramSemanticsConnection($this->store, [
        'default_parcel_stock' => '1', 'default_parcel_open' => '2', 'default_fragile' => true, 'default_replace' => false,
    ]);
    Product::create(['store_id' => $this->store->id, 'name' => 'Lamp', 'sku' => 'LUME-17', 'type' => 'simple', 'status' => 'active', 'price' => 180]);
    $order = paramSemanticsOrder($this->store, $this->city, $this->owner, [
        ['name' => 'Lamp', 'sku' => 'LUME-17', 'quantity' => 1, 'unit_price' => 180, 'line_total' => 180],
    ]);

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Some provider error'],
    ], 200)]);

    $response = $this->actingAs($this->dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect();

    $response->assertSessionHas('shipment_issue', function ($issue) {
        expect($issue['parcel_open_sent'])->toBe('2')
            ->and($issue['parcel_fragile_sent'])->toBe('1')
            ->and($issue['parcel_replace_sent'])->toBe('0')
            ->and($issue['products_json_preview'])->toContain('"qnty":1')
            ->and($issue['products_count'])->toBe(1);

        $flat = json_encode($issue);
        expect($flat)->not->toContain('super-secret')->not->toContain('CUSTX');

        return true;
    });
});
