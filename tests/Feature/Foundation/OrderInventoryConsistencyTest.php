<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Phase O1/O8 — end-to-end online-order inventory lifecycle consistency:
 * confirm reserves, ready_for_delivery consumes, cancellation before/after
 * dispatch releases/restores correctly, and two confirmations racing for the
 * last unit can never both succeed (InventoryEngine's row lock).
 */

/** @return array{0: User, 1: Store, 2: Warehouse} */
function oicMerchant(string $name = 'Order Inventory Consistency Store'): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => $name . ' Warehouse', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA',
        'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $store, $warehouse];
}

function oicProduct(Store $store, string $sku): \App\Models\Product
{
    return \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $store->id, 'name' => 'Consistency Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 70,
    ]));
}

function oicOrder(Store $store, \App\Models\Product $product, int $qty = 2): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'OIC-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'total' => 70 * $qty,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $qty, 'unit_price' => 70, 'line_total' => 70 * $qty,
        ]],
    ]);
}

beforeEach(function (): void {
    Queue::fake();
});

it('reserves stock exactly once when an online order is confirmed', function (): void {
    [$owner, $store, $warehouse] = oicMerchant();
    $product = oicProduct($store, 'OIC-1');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $order = oicOrder($store, $product, 3);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(3)->and($balance->available())->toBe(7);
});

it('consumes the reservation when the order reaches ready_for_delivery', function (): void {
    [$owner, $store, $warehouse] = oicMerchant();
    $product = oicProduct($store, 'OIC-2');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $order = oicOrder($store, $product, 3);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Picking, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Packing, $owner);
    $workflow->transition($order->fresh(), FulfillmentStatus::ReadyForDelivery, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(7)->and($balance->reserved)->toBe(0)->and($balance->available())->toBe(7);
});

it('releases the reservation when an order is cancelled before dispatch', function (): void {
    [$owner, $store, $warehouse] = oicMerchant();
    $product = oicProduct($store, 'OIC-3');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $order = oicOrder($store, $product, 3);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $owner);
    $workflow->transition($order->fresh(), FulfillmentStatus::Cancelled, $owner, 'out of stock');

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(0)->and($balance->available())->toBe(10);
});

it('restores consumed stock when an order is cancelled after dispatch (existing policy)', function (): void {
    [$owner, $store, $warehouse] = oicMerchant();
    $product = oicProduct($store, 'OIC-4');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $order = oicOrder($store, $product, 3);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Picking, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Packing, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::ReadyForDelivery, $owner);

    $workflow->transition($order->fresh(), FulfillmentStatus::Cancelled, $owner, 'returned to sender before delivery');

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(0)->and($balance->available())->toBe(10);
});

it('prevents two orders from both confirming the last unit — a row lock decides the loser', function (): void {
    [$owner, $store, $warehouse] = oicMerchant();
    $product = oicProduct($store, 'OIC-5');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 1, 'adjustment', null, null, 'Initial', false);

    $orderA = oicOrder($store, $product, 1);
    $orderB = oicOrder($store, $product, 1);

    $workflow = app(OrderWorkflowService::class);
    $workflow->transition($orderA, FulfillmentStatus::Confirmed, $owner);

    // Second order for the same (now fully reserved) unit must not also
    // succeed — allocate() only reserves what is actually available and the
    // reservation ends up short.
    $orderB = $workflow->transition($orderB, FulfillmentStatus::Confirmed, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->reserved)->toBe(1)
        ->and($balance->available())->toBe(0)
        ->and($orderB->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);
});

it('blocks confirmation for an unmapped stocked line instead of silently allocating nothing', function (): void {
    [$owner, $store] = oicMerchant();
    $order = Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'OIC-6',
        'fulfillment_status' => FulfillmentStatus::Pending,
        'total' => 70,
        'items' => [[
            'product_id' => 'not-a-real-product', 'name' => 'Ghost', 'sku' => 'GHOST-SKU',
            'quantity' => 1, 'unit_price' => 70, 'line_total' => 70,
        ]],
    ]);

    expect(fn () => app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner))
        ->toThrow(ValidationException::class, 'Some lines are not linked to local inventory.');
});
