<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Inventory\WaitingStockReallocationService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * Waiting Stock Reallocation — the core fix: an order confirmed with missing
 * stock creates a line-level InventoryReservation shortage; adding stock to
 * the SAME target warehouse (no transfer involved) automatically reserves it
 * and releases the order to Pick & Pack, FIFO across every order waiting on
 * that item, never consuming on_hand and never double-reserving.
 */

/** @return array{0: User, 1: Organization, 2: Store, 3: Warehouse} */
function wsrMerchant(string $name = 'Waiting Stock Reallocation Store'): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => $name . ' Warehouse', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $org, $store, $warehouse];
}

function wsrProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Reallocation Item', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]));
}

function wsrOrder(Store $store, Product $product, int $qty): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'WSR-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $qty, 'unit_price' => 100, 'line_total' => 100 * $qty,
        ]],
    ]);
}

it('creates a line-level shortage (InventoryReservation) when a confirmed order has no stock', function (): void {
    [$user, , $store, $warehouse] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-1');
    $order = wsrOrder($store, $product, 3);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $reservation = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))
        ->first());

    expect($reservation)->not->toBeNull()
        ->and($reservation->requested_quantity)->toBe(3)
        ->and($reservation->reserved_quantity)->toBe(0)
        ->and($reservation->shortage_quantity)->toBe(3);
});

it('shows the order in Waiting for Stock', function (): void {
    [$user, , $store] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-2');
    $order = wsrOrder($store, $product, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $this->actingAs($user)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.shortage.lines.0.missing', 2));
});

it('does not label a single-warehouse order "Waiting for transfer"', function (): void {
    [$user, , $store] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-3');
    $order = wsrOrder($store, $product, 1);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $this->actingAs($user)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.shortage.state', 'awaiting_restock')
            ->where('orders.0.shortage.state_label', 'Waiting for stock'));
});

it('rechecks and reserves when stock is added to the target warehouse, moving the order to ready_for_picking', function (): void {
    [$user, , $store, $warehouse] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-4');
    $order = wsrOrder($store, $product, 5);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->adjustOnHand($item, $warehouse, 5, 'adjustment', null, $user, 'Manual restock');

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(5)
        ->and($balance->reserved)->toBe(5)
        ->and($balance->available())->toBe(0);
});

it('never consumes on_hand during recheck — only reserves', function (): void {
    [$user, , $store, $warehouse] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-5');
    $order = wsrOrder($store, $product, 4);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    $before = app(InventoryEngine::class)->balance($item, $warehouse)->on_hand;

    app(InventoryEngine::class)->adjustOnHand($item, $warehouse, 4, 'adjustment', null, $user, 'Manual restock');

    $after = app(InventoryEngine::class)->balance($item, $warehouse)->on_hand;
    expect($after)->toBe($before + 4); // on_hand only reflects the manual add, nothing was consumed
});

it('keeps the order waiting and updates the missing quantity on a partial restock', function (): void {
    [$user, , $store, $warehouse] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-6');
    $order = wsrOrder($store, $product, 10);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->adjustOnHand($item, $warehouse, 4, 'adjustment', null, $user, 'Partial restock');

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $reservation = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))
        ->first());

    expect($reservation->reserved_quantity)->toBe(4)
        ->and($reservation->shortage_quantity)->toBe(6);
});

it('allocates FIFO across multiple waiting orders for the same item', function (): void {
    [$user, , $store, $warehouse] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-7');

    $older = wsrOrder($store, $product, 5);
    $older->update(['created_at' => now()->subHour()]);
    $older = app(OrderWorkflowService::class)->transition($older, FulfillmentStatus::Confirmed, $user);
    // Force the older allocation's allocated_at further back so FIFO order is unambiguous.
    $older->inventoryAllocation()->update(['allocated_at' => now()->subHour()]);

    $newer = wsrOrder($store, $product, 5);
    $newer = app(OrderWorkflowService::class)->transition($newer, FulfillmentStatus::Confirmed, $user);

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    // Only enough for the OLDER order.
    app(InventoryEngine::class)->adjustOnHand($item, $warehouse, 5, 'adjustment', null, $user, 'Partial restock');

    expect($older->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking)
        ->and($newer->fresh()->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);
});

it('does not double-reserve when recheck runs twice', function (): void {
    [$user, , $store, $warehouse] = wsrMerchant();
    $product = wsrProduct($store, 'WSR-8');
    $order = wsrOrder($store, $product, 3);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $user);

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->adjustOnHand($item, $warehouse, 3, 'adjustment', null, $user, 'Restock');

    // Explicitly rerun the same recheck a second time — the shortage is
    // already 0, so this must be a safe no-op, not an over-reservation.
    app(WaitingStockReallocationService::class)->recheck($item, $warehouse, $user);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->reserved)->toBe(3)
        ->and($balance->on_hand)->toBe(3);
});
