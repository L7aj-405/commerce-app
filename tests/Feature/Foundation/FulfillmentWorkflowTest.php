<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
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
use App\Services\Orders\OrderAssignmentService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use Illuminate\Validation\ValidationException;

function fulfillmentMerchant(string $name = 'Workflow Merchant'): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => $name . ' Brand',
        'type' => 'online',
        'status' => 'active',
        'country' => 'MA',
        'currency' => 'MAD',
    ]);

    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id,
        'owner_organization_id' => $org->id,
        'operator_organization_id' => $org->id,
        'name' => $name . ' Warehouse',
        'type' => Warehouse::TYPE_STANDARD,
        'country' => 'MA',
        'is_active' => true,
        'is_default' => true,
    ]));

    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return compact('user', 'org', 'store', 'warehouse');
}

function fulfillmentProduct(Store $store, string $sku, string $name = 'Workflow item'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => $sku,
        'type' => 'simple',
        'status' => 'active',
        'price' => 100,
    ]));
}

it('turns confirmation into a ready-for-picking warehouse task when stock is available', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = fulfillmentMerchant('Ready flow');
    $product = fulfillmentProduct($store, 'WF-READY-001');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    $engine = app(InventoryEngine::class);
    $engine->setOnHand($item, $warehouse, 5, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'status' => OrderStatus::Pending,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 2,
            'price' => 100,
        ]],
    ]);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking)
        ->and($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->inventoryAllocation?->warehouse_id)->toBe($warehouse->id)
        ->and($engine->balance($item, $warehouse)->on_hand)->toBe(5)
        ->and($engine->balance($item, $warehouse)->reserved)->toBe(2)
        ->and($engine->balance($item, $warehouse)->available())->toBe(3);
});

it('keeps an order waiting for stock until the replenishment transfer is received', function (): void {
    ['user' => $user, 'org' => $org, 'store' => $store, 'warehouse' => $casa] = fulfillmentMerchant('Transfer flow');
    $marrakech = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id,
        'owner_organization_id' => $org->id,
        'operator_organization_id' => $org->id,
        'name' => 'Marrakech Hub',
        'city' => 'Marrakech',
        'country' => 'MA',
        'type' => Warehouse::TYPE_STANDARD,
        'is_active' => true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id => ['is_primary' => false, 'priority' => 2]]);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    $product = fulfillmentProduct($store, 'WF-TRANSFER-001');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    $engine = app(InventoryEngine::class);
    $engine->setOnHand($item, $marrakech, 3, 'initial_import', null, $user);
    $engine->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'status' => OrderStatus::Pending,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 4,
            'price' => 100,
        ]],
    ]);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);
    $transfer = InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()->firstOrFail());

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock)
        ->and($order->inventoryAllocation?->warehouse_id)->toBe($marrakech->id)
        ->and($engine->balance($item, $marrakech)->reserved)->toBe(3)
        ->and($engine->balance($item, $casa)->transfer_reserved)->toBe(1);

    $moves = app(InventoryTransferService::class);
    $moves->ship($transfer, $user);
    $moves->receive($transfer->refresh(), $user);

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking)
        ->and($engine->balance($item, $marrakech)->reserved)->toBe(4)
        ->and($engine->balance($item, $casa)->on_hand)->toBe(9);
});

it('does not let a picker claim a waiting-for-stock order', function (): void {
    ['user' => $user, 'org' => $org, 'store' => $store, 'warehouse' => $casa] = fulfillmentMerchant('Claim guard');
    $marrakech = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id,
        'owner_organization_id' => $org->id,
        'operator_organization_id' => $org->id,
        'name' => 'Marrakech Hub',
        'city' => 'Marrakech',
        'country' => 'MA',
        'type' => Warehouse::TYPE_STANDARD,
        'is_active' => true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id => ['is_primary' => false, 'priority' => 2]]);
    $city = City::query()->where('code', 'MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    $product = fulfillmentProduct($store, 'WF-CLAIM-001');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    $engine = app(InventoryEngine::class);
    $engine->setOnHand($item, $marrakech, 3, 'initial_import', null, $user);
    $engine->setOnHand($item, $casa, 10, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'status' => OrderStatus::Pending,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 4,
            'price' => 100,
        ]],
    ]);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect(fn () => app(OrderAssignmentService::class)->claim($order->fresh(), $user))
        ->toThrow(ValidationException::class);
});

it('runs the pick then pack then dispatch handoff without consuming stock before dispatch', function (): void {
    ['user' => $user, 'store' => $store, 'warehouse' => $warehouse] = fulfillmentMerchant('Pick pack');
    $product = fulfillmentProduct($store, 'WF-PACK-001');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    $engine = app(InventoryEngine::class);
    $engine->setOnHand($item, $warehouse, 5, 'initial_import', null, $user);

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'status' => OrderStatus::Pending,
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'price' => 100,
        ]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $user);
    $workflow->transition($order->refresh(), FulfillmentStatus::Picking, $user);
    $workflow->transition($order->refresh(), FulfillmentStatus::Packing, $user);

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Packing)
        ->and($engine->balance($item, $warehouse)->on_hand)->toBe(5)
        ->and($engine->balance($item, $warehouse)->reserved)->toBe(1);

    $workflow->transition($order->refresh(), FulfillmentStatus::ReadyForDelivery, $user);

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForDelivery)
        ->and($engine->balance($item, $warehouse)->on_hand)->toBe(4)
        ->and($engine->balance($item, $warehouse)->reserved)->toBe(0);
});
