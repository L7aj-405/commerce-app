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
 * Transfer Receiving must stay empty until a transfer is genuinely
 * shipped/in_transit — a shortage that only auto-created a REQUESTED
 * transfer (not yet shipped) must not appear there. Receiving the transfer
 * increases the target warehouse's stock and releases the waiting order,
 * exactly as it did before this phase (regression coverage).
 */

/** @return array{0: User, 1: Organization, 2: Store, 3: Warehouse} */
function wstfMerchant(string $name = 'Waiting Stock Transfer Flow Store'): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => $name . ' Casa Hub', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $org, $store, $warehouse];
}

function wstfMarrakechSetup(Organization $org, Store $store, User $user): Warehouse
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

it('keeps Transfer Receiving empty while the shortage transfer is only requested, not shipped', function (): void {
    [$user, $org, $store, $casa] = wstfMerchant();
    $marrakech = wstfMarrakechSetup($org, $store, $user);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Transfer Flow Item', 'sku' => 'WSTF-1', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 1, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'WSTF-1-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 4, 'unit_price' => 100, 'line_total' => 400]],
    ]);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $transfer = InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()->firstOrFail());
    expect($transfer->status)->toBe(InventoryTransfer::REQUESTED);

    $this->actingAs($user)->get('/dashboard/operations/transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers', 0));
});

it('appears in Transfer Receiving once shipped, and receiving it restocks + releases the waiting order', function (): void {
    [$user, $org, $store, $casa] = wstfMerchant();
    $marrakech = wstfMarrakechSetup($org, $store, $user);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Transfer Flow Item 2', 'sku' => 'WSTF-2', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $marrakech, 1, 'initial_import', null, $user);
    app(InventoryEngine::class)->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'WSTF-2-ORD', 'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 4, 'unit_price' => 100, 'line_total' => 400]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $transfer = InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()->firstOrFail());
    $transfers = app(InventoryTransferService::class);
    $transfers->ship($transfer, $user);

    $this->actingAs($user)->get('/dashboard/operations/transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers', 1));

    $this->actingAs($user)->post("/dashboard/operations/transfers/{$transfer->id}/receive")
        ->assertSessionHas('success');

    // marrakech started at 1, needed 4 -> shortage of 3 moved from casa (10 -> 7).
    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking)
        ->and(app(InventoryEngine::class)->balance($item, $marrakech)->on_hand)->toBe(4)
        ->and(app(InventoryEngine::class)->balance($item, $casa)->on_hand)->toBe(7);

    $this->actingAs($user)->get('/dashboard/operations/transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers', 0));
});
