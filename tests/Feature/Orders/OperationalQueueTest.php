<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\City;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * Same merchant/product helpers as FulfillmentWorkflowTest — one org, one
 * store, one warehouse it owns and operates, ready to confirm orders into.
 */
function opsMerchant(string $name = 'Ops Merchant'): array
{
    $user  = User::factory()->create(['onboarding_completed_at' => now()]);
    $org   = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id,
        'user_id'         => $user->id,
        'name'            => $name . ' Brand',
        'type'            => 'online',
        'status'          => 'active',
        'country'         => 'MA',
        'currency'        => 'MAD',
    ]);

    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id'                  => $user->id,
        'owner_organization_id'    => $org->id,
        'operator_organization_id' => $org->id,
        'name'                     => $name . ' Warehouse',
        'type'                     => Warehouse::TYPE_STANDARD,
        'country'                  => 'MA',
        'is_active'                => true,
        'is_default'               => true,
    ]));

    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);
    $store->ensureDefaultRoles();

    return compact('user', 'org', 'store', 'warehouse');
}

function opsProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id,
        'name'     => 'Ops item ' . $sku,
        'sku'      => $sku,
        'type'     => 'simple',
        'status'   => 'active',
        'price'    => 100,
    ]));
}

function opsPendingOrder(Store $store, Product $product, int $quantity = 1, ?string $cityId = null): Order
{
    return Order::factory()->create([
        'store_id'           => $store->id,
        'status'             => OrderStatus::Pending,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id'   => $cityId,
        'items'              => [[
            'product_id' => $product->id,
            'name'       => $product->name,
            'sku'        => $product->sku,
            'quantity'   => $quantity,
            'price'      => 100,
        ]],
    ]);
}

/**
 * Grant $user a StoreRole (by slug) on $store — the normal team-membership
 * path. The legacy `role` column only accepts a small fixed set of values
 * (owner/manager/cashier/member), unrelated to the granular store_role_id —
 * 'manager' is a safe placeholder here, same as other Foundation tests use.
 */
function opsGrantRole(Store $store, User $user, string $roleSlug): void
{
    $store->ensureDefaultRoles();

    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $user->id,
        'role'          => 'manager',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);
}

it('lists pending orders in the confirmation queue only for the active tenant', function (): void {
    ['user' => $ownerA, 'store' => $storeA] = opsMerchant('Confirm A');
    ['store' => $storeB] = opsMerchant('Confirm B');

    $productA = opsProduct($storeA, 'CONF-A-001');
    $productB = opsProduct($storeB, 'CONF-B-001');
    opsPendingOrder($storeA, $productA);
    opsPendingOrder($storeB, $productB);

    $this->actingAs($ownerA)
        ->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1));
});

it('confirming an order with a city and corrected contact details triggers allocation', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = opsMerchant('Confirm allocate');
    $product = opsProduct($store, 'CONF-ALLOC-001');
    $item    = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $user);

    $order = opsPendingOrder($store, $product, 2);

    $this->actingAs($user)
        ->post("/dashboard/orders/online/{$order->id}/status", [
            'status'           => 'confirmed',
            'customer_name'    => 'Corrected Name',
            'customer_phone'   => '+212600000000',
            'shipping_address' => '1 Test Street',
        ])
        ->assertRedirect();

    $order->refresh();

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking)
        ->and($order->customer_name)->toBe('Corrected Name')
        ->and($order->customer_phone)->toBe('+212600000000');
});

it('shows a fully-stocked order in the picking queue', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = opsMerchant('Pick full stock');
    $product = opsProduct($store, 'PICK-001');
    $item    = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $user);

    $order = opsPendingOrder($store, $product, 1);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $this->actingAs($user)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1));
});

it('shows a short-stock order in waiting-for-stock but not in picking', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = opsMerchant('Wait for stock');
    $product = opsProduct($store, 'WAIT-001');
    $item    = app(CatalogInventoryService::class)->forCatalog($product);
    // Only 1 on hand, order needs 3 — nothing else can fill it, so it stays short.
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 1, 'initial_import', null, $user);

    $order = opsPendingOrder($store, $product, 3);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $this->actingAs($user)->get('/dashboard/operations/waiting-stock')
        ->assertOk()->assertInertia(fn ($page) => $page->has('orders', 1));

    $this->actingAs($user)->get('/dashboard/operations/picking')
        ->assertOk()->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('moves an order into the picking queue once its transfer is received', function (): void {
    ['user' => $user, 'org' => $org, 'store' => $store, 'warehouse' => $casa] = opsMerchant('Transfer receive');
    $marrakech = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id'                  => $user->id,
        'owner_organization_id'    => $org->id,
        'operator_organization_id' => $org->id,
        'name'                     => 'Marrakech hub',
        'city'                     => 'Marrakech',
        'country'                  => 'MA',
        'type'                     => Warehouse::TYPE_STANDARD,
        'is_active'                => true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id => ['is_primary' => false, 'priority' => 2]]);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    $product = opsProduct($store, 'XFER-001');
    $item    = app(CatalogInventoryService::class)->forCatalog($product);
    $engine  = app(InventoryEngine::class);
    // Marrakech (the city-routed destination) is short; Casa (the default hub)
    // holds enough to be found as the transfer source — same shape as
    // FulfillmentWorkflowTest's "Transfer flow" scenario.
    $engine->setOnHand($item, $marrakech, 1, 'initial_import', null, $user);
    $engine->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = opsPendingOrder($store, $product, 4, $city->id);
    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $user);

    $this->actingAs($user)->get('/dashboard/operations/waiting-stock')
        ->assertOk()->assertInertia(fn ($page) => $page->has('orders', 1));

    $transfer = InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()->latest()->firstOrFail());
    $transfers = app(InventoryTransferService::class);
    $transfers->ship($transfer, $user);
    $transfers->receive($transfer->refresh(), $user);

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);

    $this->actingAs($user)->get('/dashboard/operations/picking')
        ->assertOk()->assertInertia(fn ($page) => $page->has('orders', 1));

    $this->actingAs($user)->get('/dashboard/operations/waiting-stock')
        ->assertOk()->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('does not let a user claim an order outside their warehouse/operator access', function (): void {
    ['store' => $storeA, 'warehouse' => $warehouseA, 'user' => $ownerA] = opsMerchant('Isolation A');
    ['user' => $ownerB] = opsMerchant('Isolation B');

    $productA = opsProduct($storeA, 'ISO-A-001');
    $itemA    = app(CatalogInventoryService::class)->forCatalog($productA);
    app(InventoryEngine::class)->setOnHand($itemA, $warehouseA, 5, 'initial_import', null, $ownerA);

    $orderA = opsPendingOrder($storeA, $productA, 1);
    app(OrderWorkflowService::class)->transition($orderA, FulfillmentStatus::Confirmed, $ownerA);

    // Owner B's active store/warehouse share nothing with A, so A's order is
    // outside their operator scope: claiming it 404s (their active store
    // cannot resolve it) and it never appears in their own picking queue.
    $this->actingAs($ownerB)
        ->post("/dashboard/departments/online/{$orderA->id}/claim")
        ->assertNotFound();

    $this->actingAs($ownerB)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('lets an agency see client work only for clients whose warehouse it operates', function (): void {
    $owner   = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency  = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Fulfil Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $owner, ['client_name' => 'Client A', 'brand_name' => 'Brand A', 'country' => 'MA', 'currency' => 'MAD']);
    $clientB = $service->createClient($agency, $owner, ['client_name' => 'Client B', 'brand_name' => 'Brand B', 'country' => 'MA', 'currency' => 'MAD']);
    $warehouse = $service->createAgencyWarehouse($agency, $owner, ['name' => 'Shared Hub', 'city' => 'Casablanca', 'country' => 'MA']);

    // Only Client A's fulfillment is handed to the agency's warehouse.
    $service->assignWarehouse($agency, $clientA, $warehouse, $owner);

    $storeA = $clientA->stores->first();
    $storeB = $clientB->stores->first();
    opsGrantRole($storeA, $owner, 'warehouse');
    opsGrantRole($storeB, $owner, 'warehouse');

    $productA = opsProduct($storeA, 'AGY-A-001');
    $itemA    = app(CatalogInventoryService::class)->forCatalog($productA);
    app(InventoryEngine::class)->setOnHand($itemA, $warehouse, 5, 'initial_import', null, $owner);
    $orderA = opsPendingOrder($storeA, $productA, 1);
    app(OrderWorkflowService::class)->transition($orderA, FulfillmentStatus::Confirmed, $owner);

    // Client B has its own independent stock/warehouse, untouched by the agency.
    ['warehouse' => $warehouseB] = opsMerchant('Client B own warehouse (unused)');
    $productB = opsProduct($storeB, 'AGY-B-001');
    opsPendingOrder($storeB, $productB, 1); // left Pending — never allocated to the shared warehouse.

    $this->actingAs($owner)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1)
            ->where('orders.0.client_organization_name', 'Client A'));
});

it('never leaks Client A orders into Client B\'s queue when they share one agency warehouse', function (): void {
    // The agency owner (org owner/admin) is privileged across every client it
    // manages — App\Models\User::canOperateClientOrganization() grants that
    // unconditionally, by design, once an AgencyClientRelationship exists. So
    // the real isolation boundary this scenario exercises is for RANK-AND-FILE
    // agency staff: someone who is merely an active agency member (not an
    // owner/admin) and therefore is NOT privileged on any client store, and
    // must be given an explicit team role per client, exactly like any other
    // staff member.
    $owner   = User::factory()->create(['onboarding_completed_at' => now()]);
    $picker  = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency  = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Shared Hub Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    OrganizationMember::create([
        'organization_id' => $agency->id,
        'user_id'         => $picker->id,
        'role'            => OrganizationMember::ROLE_MEMBER,
        'is_active'       => true,
        'joined_at'       => now(),
    ]);

    $clientA = $service->createClient($agency, $owner, ['client_name' => 'Shared A', 'brand_name' => 'Shared Brand A', 'country' => 'MA', 'currency' => 'MAD']);
    $clientB = $service->createClient($agency, $owner, ['client_name' => 'Shared B', 'brand_name' => 'Shared Brand B', 'country' => 'MA', 'currency' => 'MAD']);
    $warehouse = $service->createAgencyWarehouse($agency, $owner, ['name' => 'Shared Hub 2', 'city' => 'Casablanca', 'country' => 'MA']);

    // Both clients' fulfillment is handed to the SAME physical warehouse.
    $service->assignWarehouse($agency, $clientA, $warehouse, $owner);
    $service->assignWarehouse($agency, $clientB, $warehouse, $owner);

    $storeA = $clientA->stores->first();
    $storeB = $clientB->stores->first();

    // A StoreMember row is only honored while the user is also an active
    // member of that store's owning organization (User::storeMembershipFor's
    // outer boundary check) — so, exactly as a real agency would configure
    // it, the picker is added to Client A's organization and given a store
    // role there. Client B never did either, even though the same warehouse
    // serves both.
    OrganizationMember::create([
        'organization_id' => $clientA->id,
        'user_id'         => $picker->id,
        'role'            => OrganizationMember::ROLE_MEMBER,
        'is_active'       => true,
        'joined_at'       => now(),
    ]);
    opsGrantRole($storeA, $picker, 'warehouse');

    $productA = opsProduct($storeA, 'LEAK-A-001');
    $itemA    = app(CatalogInventoryService::class)->forCatalog($productA);
    app(InventoryEngine::class)->setOnHand($itemA, $warehouse, 5, 'initial_import', null, $owner);
    $orderA = opsPendingOrder($storeA, $productA, 1);
    app(OrderWorkflowService::class)->transition($orderA, FulfillmentStatus::Confirmed, $owner);

    $productB = opsProduct($storeB, 'LEAK-B-001');
    $itemB    = app(CatalogInventoryService::class)->forCatalog($productB);
    app(InventoryEngine::class)->setOnHand($itemB, $warehouse, 5, 'initial_import', null, $owner);
    $orderB = opsPendingOrder($storeB, $productB, 1);
    app(OrderWorkflowService::class)->transition($orderB, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($picker)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1)
            ->where('orders.0.client_organization_name', 'Shared A'));
});

it('shows only packing-status orders in the packing queue', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = opsMerchant('Packing only');
    $product = opsProduct($store, 'PACK-001');
    $item    = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $user);

    $workflow = app(OrderWorkflowService::class);

    $picking = opsPendingOrder($store, $product, 1);
    $picking = $workflow->transition($picking, FulfillmentStatus::Confirmed, $user);
    // Stays at ready_for_picking — must not show up in packing.

    $packing = opsPendingOrder($store, $product, 1);
    $packing = $workflow->transition($packing, FulfillmentStatus::Confirmed, $user);
    $packing = $workflow->transition($packing->refresh(), FulfillmentStatus::Picking, $user);
    $workflow->transition($packing->refresh(), FulfillmentStatus::Packing, $user);

    $this->actingAs($user)
        ->get('/dashboard/operations/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1)
            ->where('orders.0.reference', $packing->fresh()->order_number));
});

it('shows only ready-for-delivery orders in the ready-for-delivery queue', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = opsMerchant('Ready only');
    $product = opsProduct($store, 'READY-001');
    $item    = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $user);

    $workflow = app(OrderWorkflowService::class);

    $stillPacking = opsPendingOrder($store, $product, 1);
    $stillPacking = $workflow->transition($stillPacking, FulfillmentStatus::Confirmed, $user);
    $stillPacking = $workflow->transition($stillPacking->refresh(), FulfillmentStatus::Picking, $user);
    $workflow->transition($stillPacking->refresh(), FulfillmentStatus::Packing, $user);

    $ready = opsPendingOrder($store, $product, 1);
    $ready = $workflow->transition($ready, FulfillmentStatus::Confirmed, $user);
    $ready = $workflow->transition($ready->refresh(), FulfillmentStatus::Picking, $user);
    $ready = $workflow->transition($ready->refresh(), FulfillmentStatus::Packing, $user);
    $ready = $workflow->transition($ready->refresh(), FulfillmentStatus::ReadyForDelivery, $user);

    $this->actingAs($user)
        ->get('/dashboard/operations/ready-delivery')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1)
            ->where('orders.0.reference', $ready->fresh()->order_number));
});
