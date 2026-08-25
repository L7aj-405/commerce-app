<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * City-to-warehouse allocation, including the "no mapping found" fallback
 * warning and the "confirmed city changed before confirmation" behavior —
 * both required by the Waiting Stock Reallocation phase's rule that a
 * shortage's target warehouse must come from the existing city rules, never
 * silently allocate to an unrelated one.
 */

/** @return array{0: User, 1: Organization, 2: Store, 3: Warehouse} */
function cwasMerchant(string $name = 'City Warehouse Shortage Store'): array
{
    $user = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $store->ensureDefaultRoles();
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => $name . ' Casa Hub', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $org, $store, $warehouse];
}

function cwasMarrakech(Organization $org, Store $store, User $user): Warehouse
{
    $marrakech = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => 'Marrakech Hub', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id => ['is_primary' => false, 'priority' => 2]]);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    return $marrakech;
}

it('chooses the target warehouse based on the order city mapping', function (): void {
    [$user, $org, $store, $casa] = cwasMerchant();
    $marrakech = cwasMarrakech($org, $store, $user);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'City Map Item', 'sku' => 'CWAS-1', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 5, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 5, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'CWAS-1-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->inventoryAllocation?->warehouse_id)->toBe($marrakech->id)
        ->and(app(InventoryEngine::class)->balance($item, $marrakech)->reserved)->toBe(2)
        ->and(app(InventoryEngine::class)->balance($item, $casa)->reserved)->toBe(0);
});

it('falls back to the store default warehouse and records a warning when no warehouse serves the city', function (): void {
    [$user, , $store, $casa] = cwasMerchant();
    $city = City::query()->where('code', 'MA-TANGER')->firstOrFail(); // no warehouse configured to serve Tanger

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'No City Map Item', 'sku' => 'CWAS-2', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $casa, 5, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'CWAS-2-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->inventoryAllocation?->warehouse_id)->toBe($casa->id)
        ->and($order->inventoryAllocation?->notes)->toContain('No warehouse configured to serve');
});

it('does not warn when the order has no city at all (nothing to map)', function (): void {
    [$user, , $store, $casa] = cwasMerchant();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'No City At All Item', 'sku' => 'CWAS-3', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $casa, 5, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'CWAS-3-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->inventoryAllocation?->notes)->toBeNull();
});

it('uses the newly selected city, not the original one, when the agent changes it before confirming', function (): void {
    [$user, $org, $store, $casa] = cwasMerchant();
    $marrakech = cwasMarrakech($org, $store, $user);
    $original = City::query()->where('code', 'MA-TANGER')->firstOrFail();
    $corrected = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Changed City Item', 'sku' => 'CWAS-4', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 5, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 5, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'CWAS-4-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $original->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);

    // Exactly what OrderController::updateStatus() does: persist the
    // agent's corrected city BEFORE calling the workflow transition.
    $order->update(['shipping_city_id' => $corrected->id]);
    $order = app(OrderWorkflowService::class)->transition($order->fresh(), FulfillmentStatus::Confirmed, $user);

    expect($order->inventoryAllocation?->warehouse_id)->toBe($marrakech->id)
        ->and(app(InventoryEngine::class)->balance($item, $marrakech)->reserved)->toBe(1);
});

it('sets the shortage target to the city-mapped warehouse even when it is the one that is short', function (): void {
    [$user, $org, $store, $casa] = cwasMerchant();
    $marrakech = cwasMarrakech($org, $store, $user);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Shortage Target Item', 'sku' => 'CWAS-5', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 1, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'CWAS-5-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 4, 'unit_price' => 100, 'line_total' => 400]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock)
        ->and($order->inventoryAllocation?->warehouse_id)->toBe($marrakech->id);

    $reservation = \App\Models\InventoryReservation::withoutOrganizationTenancy(fn () => \App\Models\InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))
        ->firstOrFail());

    expect($reservation->warehouse_id)->toBe($marrakech->id);
});
