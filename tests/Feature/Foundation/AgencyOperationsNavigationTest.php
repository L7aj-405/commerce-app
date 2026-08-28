<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * Agency Operations Navigation — an agency admin operating a shared
 * warehouse for several client organizations must see a cross-client
 * Supervisor Queue (is_agency_context: true) that identifies which client
 * each row belongs to (client_organization_name), and must never see a
 * client/store it does not operate.
 */

function agOpsProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Agency Ops Item ' . $sku, 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]));
}

function agOpsPendingOrder(Store $store, Product $product): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'status' => OrderStatus::Pending,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'price' => 100,
        ]],
    ]);
}

function agOpsGrantRole(Store $store, User $user, string $roleSlug): void
{
    $store->ensureDefaultRoles();

    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $user->id, 'role' => 'manager',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
}

it('flags the supervisor queue as agency context and includes the client organization on each row', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Cross Client Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $owner, ['client_name' => 'Client Alpha', 'brand_name' => 'Alpha Brand', 'country' => 'MA', 'currency' => 'MAD']);
    $warehouse = $service->createAgencyWarehouse($agency, $owner, ['name' => 'Cross Client Hub', 'city' => 'Casablanca', 'country' => 'MA']);
    $service->assignWarehouse($agency, $clientA, $warehouse, $owner);

    $storeA = $clientA->stores->first();
    agOpsGrantRole($storeA, $owner, 'warehouse');

    $product = agOpsProduct($storeA, 'AGNAV-A-001');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $owner);
    $order = agOpsPendingOrder($storeA, $product);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('is_agency_context', true)
            ->has('orders', 1)
            ->where('orders.0.client_organization_name', 'Client Alpha'));
});

it('gives the agency admin the operations.supervise permission for the cross-client Supervisor Queues section', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Supervise Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);
    $client = $service->createClient($owner->managedAgencyOrganizations()->first(), $owner, ['client_name' => 'Supervise Client', 'brand_name' => 'Supervise Brand', 'country' => 'MA', 'currency' => 'MAD']);

    $this->actingAs($owner)
        ->withSession(['store_id' => $client->stores->first()->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($perms) => collect($perms)->contains('operations.supervise')));
});

it('never leaks an unrelated client\'s orders into the agency-wide picking queue', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Isolation Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $owner, ['client_name' => 'Isolation A', 'brand_name' => 'Isolation Brand A', 'country' => 'MA', 'currency' => 'MAD']);
    $warehouse = $service->createAgencyWarehouse($agency, $owner, ['name' => 'Isolation Hub', 'city' => 'Casablanca', 'country' => 'MA']);
    $service->assignWarehouse($agency, $clientA, $warehouse, $owner);
    $storeA = $clientA->stores->first();
    agOpsGrantRole($storeA, $owner, 'warehouse');

    // An unrelated organization/store the agency does NOT operate at all.
    $otherOwner = User::factory()->create(['onboarding_completed_at' => now()]);
    $otherOrg = app(OrganizationProvisioner::class)->createOwnedOrganization($otherOwner, 'Unrelated Merchant');
    $otherStore = Store::create([
        'organization_id' => $otherOrg->id, 'user_id' => $otherOwner->id, 'name' => 'Unrelated Store',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $otherWarehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $otherOwner->id, 'owner_organization_id' => $otherOrg->id, 'operator_organization_id' => $otherOrg->id,
        'name' => 'Unrelated Warehouse', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $otherWarehouse->accessibleOrganizations()->sync([$otherOrg->id => ['is_active' => true]]);
    $otherStore->warehouses()->sync([$otherWarehouse->id => ['is_primary' => true, 'priority' => 1]]);

    $productA = agOpsProduct($storeA, 'ISO-A-001');
    $itemA = app(CatalogInventoryService::class)->forCatalog($productA);
    app(InventoryEngine::class)->setOnHand($itemA, $warehouse, 5, 'initial_import', null, $owner);
    $orderA = agOpsPendingOrder($storeA, $productA);
    app(OrderWorkflowService::class)->transition($orderA, FulfillmentStatus::Confirmed, $owner);

    $productOther = agOpsProduct($otherStore, 'ISO-OTHER-001');
    $itemOther = app(CatalogInventoryService::class)->forCatalog($productOther);
    app(InventoryEngine::class)->setOnHand($itemOther, $otherWarehouse, 5, 'initial_import', null, $otherOwner);
    $orderOther = agOpsPendingOrder($otherStore, $productOther);
    app(OrderWorkflowService::class)->transition($orderOther, FulfillmentStatus::Confirmed, $otherOwner);

    $this->actingAs($owner)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.client_organization_name', 'Isolation A'));
});
