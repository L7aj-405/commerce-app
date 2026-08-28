<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * The UI-facing state machine (App\Support\WaitingStockState) and its use in
 * Pick & Pack: a waiting-stock order must never be presented as pickable,
 * and its allocation's waiting_state_label must only ever say "Waiting for
 * transfer" once a real transfer is actually in transit — never merely
 * because stock is short.
 */

/** @return array{0: User, 1: Organization, 2: Store, 3: Warehouse} */
function wsuiMerchant(string $name = 'Waiting Stock UI State Store'): array
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
        'name' => $name . ' Warehouse', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $org, $store, $warehouse];
}

function wsuiOrder(Store $store, Product $product, int $qty, ?string $cityId = null): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'WSUI-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $cityId,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $qty, 'unit_price' => 100, 'line_total' => 100 * $qty,
        ]],
    ]);
}

it('shows a single-warehouse waiting order in Pick & Pack as "Waiting for stock", never "Waiting for transfer"', function (): void {
    [$user, , $store] = wsuiMerchant();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'UI State Item', 'sku' => 'WSUI-1', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $order = wsuiOrder($store, $product, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $this->actingAs($user)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.status', 'waiting_for_stock')
            ->where('orders.0.allocation.waiting_state', 'awaiting_restock')
            ->where('orders.0.allocation.waiting_state_label', 'Waiting for stock'));
});

it('does not present a waiting-stock order as pickable in Pick & Pack', function (): void {
    [$user, , $store] = wsuiMerchant();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'UI State Item 2', 'sku' => 'WSUI-2', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $order = wsuiOrder($store, $product, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    // The status itself must be waiting_for_stock, not one of the
    // pickable statuses (confirmed/ready_for_picking/picking/in_progress) —
    // this is what Packing.jsx's `readyToPick`/`picking` booleans key off,
    // so a waiting order structurally cannot render the pick actions.
    $this->actingAs($user)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.status', fn ($status) => ! in_array($status, [
            'confirmed', 'ready_for_picking', 'picking', 'in_progress',
        ], true)));
});

it('labels the order "Transfer requested" once a transfer exists but has not shipped', function (): void {
    [$user, $org, $store, $casa] = wsuiMerchant();
    $marrakech = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => 'Marrakech Hub', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id => ['is_primary' => false, 'priority' => 2]]);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'UI State Item 3', 'sku' => 'WSUI-3', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 1, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = wsuiOrder($store, $product, 4, $city->id);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $this->actingAs($user)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.allocation.waiting_state_label', 'Transfer requested'));
});

it('labels the order "Waiting for transfer" only once the transfer is actually shipped/in_transit', function (): void {
    [$user, $org, $store, $casa] = wsuiMerchant();
    $marrakech = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => 'Marrakech Hub', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id => ['is_primary' => false, 'priority' => 2]]);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'UI State Item 4', 'sku' => 'WSUI-4', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 1, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = wsuiOrder($store, $product, 4, $city->id);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $transfer = InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()->firstOrFail());
    app(InventoryTransferService::class)->ship($transfer, $user);

    $this->actingAs($user)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.allocation.waiting_state_label', 'Waiting for transfer'));
});
