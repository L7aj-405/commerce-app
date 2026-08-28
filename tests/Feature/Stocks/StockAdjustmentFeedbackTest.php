<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use App\Models\Store;
use App\Models\User;

/**
 * POST /dashboard/stock/{product}/adjust — the bulk Stock dashboard modal's
 * save endpoint. An XHR caller (the modal always sends
 * X-Requested-With: XMLHttpRequest) gets a rich JSON result instead of a
 * bare redirect, so the modal can show what actually happened instead of
 * silently closing.
 */

/** @return array{0: User, 1: Store} */
function safWorkspace(string $name = 'Stock Adjust Feedback Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('reports waiting_orders_released and waiting_units_reserved when a set-stock adjustment releases a waiting order', function (): void {
    [$owner, $store] = safWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Feedback Item', 'sku' => 'SAF-1', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'SAF-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $response = $this->actingAs($owner)->post("/dashboard/stock/{$product->id}/adjust", [
        'mode' => 'set', 'reason' => 'adjustment', 'warehouse_id' => $warehouse->id,
        'adjustments' => [['variant_id' => null, 'quantity' => 10]],
    ], ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJson([
        'success' => true,
        'applied_count' => 1,
        'waiting_orders_released' => 1,
        'waiting_units_reserved' => 2,
    ]);
    expect($response->json('results.0.on_hand'))->toBe(10)
        ->and($response->json('results.0.reserved'))->toBe(2)
        ->and($response->json('results.0.available'))->toBe(8)
        ->and($response->json('message'))->toContain('Pick & Pack')
        ->and($response->json('links.pick_and_pack'))->not->toBeNull()
        ->and($response->json('links.waiting_stock'))->not->toBeNull();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);
});

it('reports no release and the remaining shortage when stock still does not cover waiting demand', function (): void {
    [$owner, $store] = safWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Still Short Item', 'sku' => 'SAF-2', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'SAF-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 5, 'unit_price' => 50, 'line_total' => 250]],
    ]);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $response = $this->actingAs($owner)->post("/dashboard/stock/{$product->id}/adjust", [
        'mode' => 'set', 'reason' => 'adjustment', 'warehouse_id' => $warehouse->id,
        'adjustments' => [['variant_id' => null, 'quantity' => 2]],
    ], ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJson(['waiting_orders_released' => 0]);
    expect($response->json('message'))->toContain('missing');
});

it('a damaged-goods adjustment never increases available sellable stock', function (): void {
    [$owner, $store] = safWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Damage Item', 'sku' => 'SAF-3', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 20, 'adjustment', null, $owner, 'Initial', false);

    $response = $this->actingAs($owner)->post("/dashboard/stock/{$product->id}/adjust", [
        'mode' => 'delta', 'reason' => 'damage', 'warehouse_id' => $warehouse->id,
        'adjustments' => [['variant_id' => null, 'quantity_change' => -5]],
    ], ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk();
    $before = 20;
    expect($response->json('results.0.available'))->toBeLessThan($before)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->on_hand)->toBe(15);
});

it('a resellable return adjustment can increase available and release a waiting order', function (): void {
    [$owner, $store] = safWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Return Item', 'sku' => 'SAF-4', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'SAF-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $response = $this->actingAs($owner)->post("/dashboard/stock/{$product->id}/adjust", [
        'mode' => 'delta', 'reason' => 'return', 'warehouse_id' => $warehouse->id,
        'adjustments' => [['variant_id' => null, 'quantity_change' => 3]],
    ], ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk()->assertJson(['waiting_orders_released' => 1]);
    expect($response->json('results.0.available'))->toBeGreaterThan(0)
        ->and($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);
});
