<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * Waiting Stock mapping repair — a shortage line that was dropped entirely
 * at confirm time (no InventoryReservation at all, under an older/buggier
 * order-line resolver) self-heals on Recheck instead of staying invisible
 * and permanently blocked. Simulates historical broken data by appending a
 * second order line AFTER confirmation — an order line resolver fix can
 * never retroactively fix orders confirmed before it shipped, so recheck's
 * reconciliation is what recovers them.
 */

/** @return array{0: User, 1: Store, 2: Warehouse} */
function wsmrWorkspace(string $name = 'Waiting Stock Mapping Repair Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    $warehouse = $store->getPrimaryWarehouse();

    return [$owner, $store, $warehouse];
}

function wsmrVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Repair Item', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 90,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 90, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 92, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

function wsmrOrder(Store $store, Product $product, ProductVariant $variant, int $qty): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'WSMR-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id, 'variant_id' => $variant->id, 'name' => $product->name, 'sku' => $variant->sku,
            'quantity' => $qty, 'unit_price' => 90, 'line_total' => 90 * $qty,
        ]],
    ]);
}

it('repairs a dropped line (no reservation at all) on recheck and moves the order to Pick & Pack once stock is present', function (): void {
    [$owner, $store, $warehouse] = wsmrWorkspace();
    $product = wsmrVariableProduct($store, 'WSMR-1');
    $variantS = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSMR-1-S')->firstOrFail());
    $variantM = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSMR-1-M')->firstOrFail());

    $order = wsmrOrder($store, $product, $variantS, 1);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    // Simulate a historical order that always carried a second line (variant
    // M) whose reservation was never created under an older/buggier
    // resolver — appended directly onto the order's own item JSON, exactly
    // what re-deriving requirements() from the CURRENT order sees today.
    $items = $order->items;
    $items[] = [
        'product_id' => $product->id, 'variant_id' => $variantM->id, 'name' => $product->name, 'sku' => $variantM->sku,
        'quantity' => 1, 'unit_price' => 92, 'line_total' => 92,
    ];
    $order->update(['items' => $items]);

    $itemS = app(CatalogInventoryService::class)->forCatalog($product, $variantS);
    $itemM = app(CatalogInventoryService::class)->forCatalog($product, $variantM);
    app(InventoryEngine::class)->setOnHand($itemS, $warehouse, 5, 'adjustment', null, $owner, 'Restock', false);
    app(InventoryEngine::class)->setOnHand($itemM, $warehouse, 5, 'adjustment', null, $owner, 'Restock', false);

    $reservationsBefore = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))->get());
    expect($reservationsBefore)->toHaveCount(1);

    $response = $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/recheck");
    $response->assertSessionHas('success');

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);

    $reservationsAfter = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))->get());
    expect($reservationsAfter)->toHaveCount(2)
        ->and((int) $reservationsAfter->sum('shortage_quantity'))->toBe(0);
});

it('recheck reconciliation is idempotent — running it twice never double-reserves', function (): void {
    [$owner, $store, $warehouse] = wsmrWorkspace();
    $product = wsmrVariableProduct($store, 'WSMR-2');
    $variantS = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSMR-2-S')->firstOrFail());
    $variantM = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSMR-2-M')->firstOrFail());

    $order = wsmrOrder($store, $product, $variantS, 1);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $items = $order->items;
    $items[] = [
        'product_id' => $product->id, 'variant_id' => $variantM->id, 'name' => $product->name, 'sku' => $variantM->sku,
        'quantity' => 1, 'unit_price' => 92, 'line_total' => 92,
    ];
    $order->update(['items' => $items]);

    $itemS = app(CatalogInventoryService::class)->forCatalog($product, $variantS);
    $itemM = app(CatalogInventoryService::class)->forCatalog($product, $variantM);
    app(InventoryEngine::class)->setOnHand($itemS, $warehouse, 5, 'adjustment', null, $owner, 'Restock', false);
    app(InventoryEngine::class)->setOnHand($itemM, $warehouse, 5, 'adjustment', null, $owner, 'Restock', false);

    $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/recheck");
    $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/recheck");

    $reservations = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))->get());

    expect($reservations)->toHaveCount(2)
        ->and((int) $reservations->sum('reserved_quantity'))->toBe(2)
        ->and(app(InventoryEngine::class)->balance($itemS, $warehouse)->reserved)->toBe(1)
        ->and(app(InventoryEngine::class)->balance($itemM, $warehouse)->reserved)->toBe(1);
});

it('waiting stock diagnostics show a clear mapping message for a line that still cannot be resolved', function (): void {
    [$owner, $store] = wsmrWorkspace();
    $product = wsmrVariableProduct($store, 'WSMR-3');
    $variantS = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSMR-3-S')->firstOrFail());

    $order = wsmrOrder($store, $product, $variantS, 1);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    // A ghost line that never resolves to anything, appended the same way a
    // pre-fix order might have carried one.
    $items = $order->items;
    $items[] = [
        'product_id' => 'ghost-remote-id', 'sku' => 'GHOST-SKU-NEVER-SEEN', 'name' => 'Ghost line',
        'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
    ];
    $order->update(['items' => $items]);

    $this->actingAs($owner)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders.0.shortage.lines', 2)
            ->where('orders.0.shortage.lines.1.reservation_id', null)
            ->where('orders.0.shortage.lines.1.debug.mapping_source', 'unmapped')
            ->where('orders.0.shortage.lines.1.debug.last_recheck_message', 'Order line is not linked to a local inventory item.'));
});
