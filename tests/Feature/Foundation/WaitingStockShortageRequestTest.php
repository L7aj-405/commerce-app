<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Jobs\ExternalStockPushJob;
use App\Models\InventoryReservation;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Queue;

/**
 * Shortage/reclamation actions on the Waiting for Stock page: "Request
 * transfer" only ever appears (and only ever succeeds) when a real other
 * warehouse actually has the stock; "Mark restock requested" is the
 * conservative fallback when none does — a visibility flag only, never a
 * fake transfer. Also covers the external-stock-sync trigger after a
 * reallocation changes final available stock.
 */

/** @return array{0: User, 1: Store} */
function wssrWorkspace(string $name = 'Waiting Stock Shortage Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function wssrWarehouse(Store $store, User $owner, string $name): Warehouse
{
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $owner->id, 'owner_organization_id' => $store->organization_id, 'operator_organization_id' => $store->organization_id,
        'name' => $name, 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => false,
    ]));
    $warehouse->accessibleOrganizations()->sync([$store->organization_id => ['is_active' => true]]);
    $store->warehouses()->syncWithoutDetaching([$warehouse->id => ['is_primary' => false, 'priority' => 2]]);

    return $warehouse;
}

function wssrOrder(Store $store, Product $product, int $qty): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'WSSR-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $qty, 'unit_price' => 100, 'line_total' => 100 * $qty,
        ]],
    ]);
}

it('offers Request transfer when another warehouse has enough available stock', function (): void {
    [$owner, $store] = wssrWorkspace();
    // City-routed so the order is forced onto $primary (the only warehouse
    // serving this city) even though it is short — exactly like the
    // pre-existing "keeps an order waiting..." fixture in
    // FulfillmentWorkflowTest. Without city routing, allocate()'s
    // full-ratio scoring would pick whichever warehouse has ALL the stock
    // directly, and no shortage (or transfer) would ever be created.
    $primary = $store->getPrimaryWarehouse();
    $primary->update(['name' => 'Primary Hub']);
    $other = wssrWarehouse($store, $owner, 'Other Hub');
    $city = \App\Models\City::query()->where('code', 'MA-CASABLANCA')->firstOrFail();
    $primary->serviceCities()->sync([$city->id => ['priority' => 1, 'is_active' => true]]);

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Transfer Offer Item', 'sku' => 'WSSR-1', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $primary, 1, 'initial_import', null, $owner);
    app(InventoryEngine::class)->setOnHand($item, $other, 10, 'initial_import', null, $owner);

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'WSSR-1-ORD',
        'fulfillment_status' => FulfillmentStatus::Pending,
        'shipping_city_id' => $city->id,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 3, 'unit_price' => 100, 'line_total' => 300]],
    ]);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.shortage.lines.0.transfer.status', InventoryTransfer::REQUESTED)
            ->where('orders.0.shortage.state', 'transfer_requested'));
});

it('lets an agent manually request a transfer for a shortage that has no automatic one yet', function (): void {
    [$owner, $store] = wssrWorkspace();
    $store->getPrimaryWarehouse();
    $other = wssrWarehouse($store, $owner, 'Manual Transfer Hub');

    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Manual Transfer Item', 'sku' => 'WSSR-2', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);

    // No stock anywhere at confirm time — order lands with NO transfer at all.
    $order = wssrOrder($store, $product, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $reservation = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))->firstOrFail());
    expect($reservation->inventory_transfer_id)->toBeNull();

    // Stock shows up later at the OTHER warehouse — the agent can now
    // manually request the transfer this order never got automatically.
    app(InventoryEngine::class)->setOnHand($item, $other, 5, 'initial_import', null, $owner);

    $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/request-transfer")
        ->assertSessionHas('success');

    expect($reservation->fresh()->inventory_transfer_id)->not->toBeNull();
});

it('fails clearly (never fabricates a transfer) when no other warehouse has the stock', function (): void {
    [$owner, $store] = wssrWorkspace();
    $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'No Source Item', 'sku' => 'WSSR-3', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $order = wssrOrder($store, $product, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/request-transfer")
        ->assertSessionHas('error');

    expect(InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()->count()))->toBe(0);
});

it('shows a restock-requested state and action when no warehouse can fill the shortage', function (): void {
    [$owner, $store] = wssrWorkspace();
    $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Restock Item', 'sku' => 'WSSR-4', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    $order = wssrOrder($store, $product, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.shortage.state', 'awaiting_restock'));

    $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/restock-requested")
        ->assertSessionHas('success');

    $this->actingAs($owner)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.shortage.state', 'restock_requested')
            ->where('orders.0.shortage.state_label', 'Restock requested'));
});

it('queues an external stock sync after reallocation changes final available stock', function (): void {
    Queue::fake();
    [$owner, $store] = wssrWorkspace();
    $warehouse = $store->getPrimaryWarehouse();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => 'wssr5.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'Sync Item', 'sku' => 'WSSR-5', 'type' => 'simple', 'status' => 'active', 'price' => 100]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'wssr-5-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '900005'],
    ]));

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    $order = wssrOrder($store, $product, 3);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    Queue::fake(); // isolate: only capture the dispatch caused by the restock below

    app(InventoryEngine::class)->adjustOnHand($item, $warehouse, 3, 'adjustment', null, $owner, 'Restock');

    Queue::assertPushed(ExternalStockPushJob::class);
});
