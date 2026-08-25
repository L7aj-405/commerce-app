<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| OzonShipmentService's products-payload fallback chain: order line SKU ->
| local variant SKU -> local product SKU -> platform/local reference ->
| skip. Never falls back to a human-readable display name.
|--------------------------------------------------------------------------
*/

function productsPayloadCallPrivate(object $service, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($service, ...$args);
}

function productsPayloadResolveRef(array $line): ?string
{
    return productsPayloadCallPrivate(app(OzonShipmentService::class), 'resolveProductRef', [$line]);
}

function productsPayloadBaseLine(array $overrides = []): array
{
    return array_merge([
        'product_id' => null,
        'variant_id' => null,
        'name' => 'A Display Name Nobody Should Ship As A Ref',
        'sku' => null,
        'quantity' => 1,
        'unit_price' => 10.0,
        'line_total' => 10.0,
        'unmapped' => false,
        'inventory_item_id' => null,
        'mapping_source' => null,
        'mapping_message' => null,
        'external_product_id' => null,
        'external_variant_id' => null,
    ], $overrides);
}

// ---------------------------------------------------------------------
// Unit-level: OzonShipmentService::resolveProductRef() fallback chain
// ---------------------------------------------------------------------

it('uses the order line SKU as the ref when present', function () {
    $ref = productsPayloadResolveRef(productsPayloadBaseLine(['sku' => 'LINE-SKU-1']));

    expect($ref)->toBe('LINE-SKU-1');
});

it('falls back to the local variant SKU when the line has no SKU', function () {
    $store = Store::factory()->create();
    $product = Product::create(['store_id' => $store->id, 'name' => 'Widget', 'type' => 'variable', 'status' => 'active', 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Red / M', 'sku' => 'VARIANT-SKU-1', 'price' => 100]);

    $ref = productsPayloadResolveRef(productsPayloadBaseLine(['sku' => null, 'variant_id' => $variant->id]));

    expect($ref)->toBe('VARIANT-SKU-1');
});

it('falls back to the local product SKU when the line has no SKU and no variant', function () {
    $store = Store::factory()->create();
    $product = Product::create(['store_id' => $store->id, 'name' => 'Widget', 'sku' => 'PRODUCT-SKU-1', 'type' => 'simple', 'status' => 'active', 'price' => 100]);

    $ref = productsPayloadResolveRef(productsPayloadBaseLine(['sku' => null, 'product_id' => $product->id]));

    expect($ref)->toBe('PRODUCT-SKU-1');
});

it('falls back to the local variant product SKU when the matched variant itself has no SKU', function () {
    $store = Store::factory()->create();
    $product = Product::create(['store_id' => $store->id, 'name' => 'Widget', 'sku' => 'PRODUCT-SKU-2', 'type' => 'variable', 'status' => 'active', 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Red / M', 'sku' => null, 'price' => 100]);

    $ref = productsPayloadResolveRef(productsPayloadBaseLine(['sku' => null, 'variant_id' => $variant->id, 'product_id' => $product->id]));

    expect($ref)->toBe('PRODUCT-SKU-2');
});

it('falls back to the external variant id when no SKU is resolvable locally', function () {
    $ref = productsPayloadResolveRef(productsPayloadBaseLine([
        'sku' => null,
        'external_variant_id' => 'EXT-VARIANT-9',
        'external_product_id' => 'EXT-PRODUCT-9',
    ]));

    expect($ref)->toBe('EXT-VARIANT-9');
});

it('falls back to the external product id when there is no external variant id', function () {
    $ref = productsPayloadResolveRef(productsPayloadBaseLine([
        'sku' => null,
        'external_product_id' => 'EXT-PRODUCT-9',
    ]));

    expect($ref)->toBe('EXT-PRODUCT-9');
});

it('falls back to the local variant id when there is no SKU and no external ids', function () {
    $store = Store::factory()->create();
    $product = Product::create(['store_id' => $store->id, 'name' => 'Widget', 'type' => 'variable', 'status' => 'active', 'price' => 100]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Red / M', 'sku' => null, 'price' => 100]);

    $ref = productsPayloadResolveRef(productsPayloadBaseLine(['sku' => null, 'variant_id' => $variant->id]));

    expect($ref)->toBe((string) $variant->id);
});

it('never falls back to the line display name — a line with no identifiers at all is skipped', function () {
    $ref = productsPayloadResolveRef(productsPayloadBaseLine([
        'sku' => null, 'product_id' => null, 'variant_id' => null,
        'external_product_id' => null, 'external_variant_id' => null,
    ]));

    expect($ref)->toBeNull();
});

it('prefers the line SKU over any local or external identifier when both exist', function () {
    $store = Store::factory()->create();
    $product = Product::create(['store_id' => $store->id, 'name' => 'Widget', 'sku' => 'PRODUCT-SKU-3', 'type' => 'simple', 'status' => 'active', 'price' => 100]);

    $ref = productsPayloadResolveRef(productsPayloadBaseLine([
        'sku' => 'LINE-WINS', 'product_id' => $product->id, 'external_product_id' => 'EXT-LOSES',
    ]));

    expect($ref)->toBe('LINE-WINS');
});

// ---------------------------------------------------------------------
// End-to-end: buildProductsPayload() via Send to Ozon
// ---------------------------------------------------------------------

function productsPayloadDispatcher(Store $store): User
{
    $role = $store->roles()->where('name', 'Dispatcher')->firstOrFail();
    $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $member;
}

it('builds products JSON with SKU refs and quantities, skipping lines with no identifiers', function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();
    $dispatcher = productsPayloadDispatcher($store);

    $city = City::create(['country_code' => 'MA', 'code' => 'RAB', 'name' => 'Rabat', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'ozon', 'provider_city_id' => '5', 'city_name' => 'Rabat']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    Product::create(['store_id' => $store->id, 'name' => 'Widget', 'sku' => 'SKU-A', 'type' => 'simple', 'status' => 'active', 'price' => 100]);

    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST1', 'api_key' => 'secret'],
        'settings' => ['default_parcel_stock' => '0'], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Amine', 'customer_phone' => '0622334455',
        'confirmed_shipping_address' => '9 Avenue Test', 'shipping_city_id' => $city->id, 'total' => 300,
        'items' => [
            ['name' => 'Widget', 'sku' => 'SKU-A', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200],
            ['name' => 'Custom service, no SKU', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100],
        ],
    ]);

    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $status) {
        $order = $workflow->transition($order, $status, $owner);
    }

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE-REF'], 200)] + ozonVerifiedFakes());

    $this->actingAs($dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertSessionHasNoErrors();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        $products = json_decode((string) $request['products'], true);

        expect($products)->toBe([['ref' => 'SKU-A', 'qnty' => 2]]);

        return true;
    });
});

it('reports has_products=false and products_count=0 in debug when no line resolves to a ref', function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();
    $dispatcher = productsPayloadDispatcher($store);

    $city = City::create(['country_code' => 'MA', 'code' => 'FES', 'name' => 'Fes', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $store->id, 'provider_code' => 'ozon', 'provider_city_id' => '9', 'city_name' => 'Fes']);
    CityDeliveryProviderMapping::create(['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST2', 'api_key' => 'secret2'],
        'settings' => ['default_parcel_stock' => '0'], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Yassine', 'customer_phone' => '0611992233',
        'confirmed_shipping_address' => '3 Rue Fes', 'shipping_city_id' => $city->id, 'total' => 150,
        'items' => [['name' => 'Custom service, no SKU', 'quantity' => 1, 'unit_price' => 150, 'line_total' => 150]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $status) {
        $order = $workflow->transition($order, $status, $owner);
    }

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Some other provider error'],
    ], 200)]);

    $response = $this->actingAs($dispatcher)
        ->post("/dashboard/delivery-shipments/orders/{$order->id}/ozon")
        ->assertRedirect();

    $response->assertSessionHas('shipment_issue', function ($issue) {
        expect($issue['has_products'])->toBeFalse()
            ->and($issue['products_count'])->toBe(0)
            ->and($issue['product_refs_preview'])->toBe([]);

        return true;
    });
});
