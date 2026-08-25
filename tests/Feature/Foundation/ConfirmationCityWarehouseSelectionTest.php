<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Events\OrderCreated;
use App\Models\City;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderAssignmentService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Confirmation Desk city selection — the city dropdown is preselected from
 * the order's platform-reported city ONLY when it exactly matches a known
 * city (never guessed further); an unrecognized raw city is surfaced as-is
 * and blocks confirmation until the agent normalizes it. The selected city
 * then drives warehouse allocation via the EXISTING city/warehouse rules
 * (WarehouseAllocationService) — unchanged by this fix.
 */

/** @return array{0: User, 1: Store} */
function cdCityWorkspace(string $name = 'Confirmation City Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cdCityWarehouse(Store $store, User $owner, string $name): Warehouse
{
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $owner->id, 'owner_organization_id' => $store->organization_id, 'operator_organization_id' => $store->organization_id,
        'name' => $name, 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => false,
    ]));
    $warehouse->accessibleOrganizations()->sync([$store->organization_id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$warehouse->id => ['is_primary' => false, 'priority' => 2]]);

    return $warehouse;
}

function cdCityShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function cdCityOrder(Store $store, PlatformConnection $connection, Product $product, string $rawCity, string $externalId): \App\Models\Order
{
    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'City Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'created_at' => now()->toIso8601String(),
        'platform_data' => ['shipping_address' => ['address1' => '1 Test Street', 'city' => $rawCity]],
    ], $connection);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('preselects the city dropdown when the platform city exactly matches a known city', function (): void {
    [$owner, $store] = cdCityWorkspace();
    $primary = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'City Product', 'sku' => 'CITY-1', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    app(InventoryEngine::class)->setOnHand(app(CatalogInventoryService::class)->forCatalog($product), $primary, 5, 'initial_import', null, $owner);

    $connection = cdCityShopifyConnection($store, 'city1.myshopify.com');
    cdCityOrder($store, $connection, $product, 'Marrakech', 'CITY-1');

    $marrakech = City::query()->where('name', 'Marrakech')->firstOrFail();

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.suggested_city_id', $marrakech->id)
            ->where('orders.0.city_recognized', true)
            ->where('orders.0.raw_city_name', null));
});

it('shows the raw city text and marks it unrecognized when it matches no known city', function (): void {
    [$owner, $store] = cdCityWorkspace();
    $primary = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'City Product 2', 'sku' => 'CITY-2', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    app(InventoryEngine::class)->setOnHand(app(CatalogInventoryService::class)->forCatalog($product), $primary, 5, 'initial_import', null, $owner);

    $connection = cdCityShopifyConnection($store, 'city2.myshopify.com');
    cdCityOrder($store, $connection, $product, 'Nowhereville', 'CITY-2');

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.suggested_city_id', null)
            ->where('orders.0.city_recognized', false)
            ->where('orders.0.raw_city_name', 'Nowhereville'));
});

it('blocks confirmation of an unrecognized-city order until the agent selects a city explicitly', function (): void {
    [$owner, $store] = cdCityWorkspace();
    $primary = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'City Product 3', 'sku' => 'CITY-3', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    app(InventoryEngine::class)->setOnHand(app(CatalogInventoryService::class)->forCatalog($product), $primary, 5, 'initial_import', null, $owner);

    $connection = cdCityShopifyConnection($store, 'city3.myshopify.com');
    $order = cdCityOrder($store, $connection, $product, 'Nowhereville', 'CITY-3');
    app(OrderAssignmentService::class)->claim($order, $owner);

    // No shipping_city_id submitted — the raw "Nowhereville" is not
    // auto-accepted no matter how confident-looking it is.
    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSessionHas('error');

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);

    $casablanca = City::query()->where('name', 'Casablanca')->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", [
        'status' => 'confirmed', 'shipping_city_id' => $casablanca->id,
    ])->assertSessionHasNoErrors();

    expect($order->fresh()->fulfillment_status)->not->toBe(FulfillmentStatus::Pending)
        ->and($order->fresh()->shipping_city_id)->toBe($casablanca->id);
});

it('confirming with a selected city allocates to the warehouse configured to serve that city', function (): void {
    [$owner, $store] = cdCityWorkspace();
    $casaHub = $store->getPrimaryWarehouse();
    $casaHub->update(['name' => 'Casa Hub']);
    $marrakechHub = cdCityWarehouse($store, $owner, 'Marrakech Hub');
    $marrakech = City::query()->where('name', 'Marrakech')->firstOrFail();
    $marrakechHub->serviceCities()->sync([$marrakech->id => ['priority' => 1, 'is_active' => true]]);

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'City Product 4', 'sku' => 'CITY-4', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $casaHub, 20, 'initial_import', null, $owner);
    app(InventoryEngine::class)->setOnHand($item, $marrakechHub, 5, 'initial_import', null, $owner);

    $connection = cdCityShopifyConnection($store, 'city4.myshopify.com');
    $order = cdCityOrder($store, $connection, $product, 'Marrakech', 'CITY-4');
    app(OrderAssignmentService::class)->claim($order, $owner);

    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", [
        'status' => 'confirmed', 'shipping_city_id' => $marrakech->id,
    ])->assertSessionHasNoErrors();

    expect(app(InventoryEngine::class)->balance($item, $marrakechHub)->reserved)->toBe(1)
        ->and(app(InventoryEngine::class)->balance($item, $casaHub)->reserved)->toBe(0);
});

it('confirming an order triggers the existing inventory reservation workflow (available stock decreases)', function (): void {
    [$owner, $store] = cdCityWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'City Product 5', 'sku' => 'CITY-5', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'initial_import', null, $owner);

    $connection = cdCityShopifyConnection($store, 'city5.myshopify.com');
    $order = cdCityOrder($store, $connection, $product, 'Casablanca', 'CITY-5');
    app(OrderAssignmentService::class)->claim($order, $owner);

    expect(app(InventoryEngine::class)->balance($item, $warehouse)->available())->toBe(10);

    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSessionHasNoErrors();

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(10)
        ->and($balance->reserved)->toBe(1)
        ->and($balance->available())->toBe(9);
});
