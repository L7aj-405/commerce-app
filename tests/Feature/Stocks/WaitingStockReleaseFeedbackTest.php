<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Waiting Stock integration — after a stock adjustment releases a waiting
 * order, a plain reload of the Stock page and the Waiting Stock page must
 * both reflect the new state (no websockets needed, per the task spec).
 */

/** @return array{0: User, 1: Store} */
function wsrfWorkspace(string $name = 'Waiting Stock Release Feedback Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('reflects increased reserved and decreased available on the Stock page after a release', function (): void {
    [$owner, $store] = wsrfWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Release Reflect Item', 'sku' => 'WSRF-1', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'WSRF-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]],
    ]);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    // Before restock: Stock page shows nothing on hand yet.
    $this->actingAs($owner)->get('/dashboard/stock')
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.on_hand', 0)
            ->where('products.data.0.reserved', 0)
            ->where('products.data.0.waiting_demand', 2));

    $this->actingAs($owner)->post("/dashboard/stock/{$product->id}/adjust", [
        'mode' => 'set', 'reason' => 'adjustment', 'warehouse_id' => $warehouse->id,
        'adjustments' => [['variant_id' => null, 'quantity' => 10]],
    ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

    // A plain reload — no websocket, no special polling endpoint — now
    // reflects the release: reserved went up, available went down,
    // waiting_demand cleared.
    $this->actingAs($owner)->get('/dashboard/stock')
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.on_hand', 10)
            ->where('products.data.0.reserved', 2)
            ->where('products.data.0.available', 8)
            ->where('products.data.0.waiting_demand', 0));
});

it('the order no longer appears in Waiting for Stock and Pick & Pack shows it after release', function (): void {
    [$owner, $store] = wsrfWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Queue Reflect Item', 'sku' => 'WSRF-2', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'WSRF-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50]],
    ]);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)->get('/dashboard/operations/waiting-stock')
        ->assertInertia(fn (Assert $page) => $page->has('orders', 1));

    $this->actingAs($owner)->post("/dashboard/stock/{$product->id}/adjust", [
        'mode' => 'set', 'reason' => 'adjustment', 'warehouse_id' => $warehouse->id,
        'adjustments' => [['variant_id' => null, 'quantity' => 5]],
    ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

    $this->actingAs($owner)->get('/dashboard/operations/waiting-stock')
        ->assertInertia(fn (Assert $page) => $page->has('orders', 0));

    $this->actingAs($owner)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('orders', 1));
});
