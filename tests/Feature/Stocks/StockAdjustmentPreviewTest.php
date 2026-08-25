<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\WarehouseInventoryBalance;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * POST /dashboard/stock/{product}/preview-adjustment — read-only. Never
 * writes a WarehouseInventoryBalance/InventoryItem/InventoryReservation row;
 * only computes what an adjustment WOULD do using the same
 * on_hand/reserved/available/waiting-release formula the engine and
 * WaitingStockReallocationService actually apply.
 */

/** @return array{0: User, 1: Store} */
function sapWorkspace(string $name = 'Stock Preview Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('set stock preview shows expected available after a waiting order can be released', function (): void {
    [$owner, $store] = sapWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Preview Item', 'sku' => 'SAP-1', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'SAP-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50]],
    ]);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $response = $this->actingAs($owner)->postJson("/dashboard/stock/{$product->id}/preview-adjustment", [
        'warehouse_id' => $warehouse->id,
        'mode' => 'set',
        'quantity' => 10,
    ]);

    $response->assertOk()->assertJson([
        'current_on_hand' => 0,
        'current_reserved' => 0,
        'current_available' => 0,
        'waiting_demand' => 1,
        'releasable_waiting_units' => 1,
        'expected_on_hand' => 10,
        'expected_reserved' => 1,
        'expected_available' => 9,
        'affected_waiting_orders_count' => 1,
    ]);
});

it('preview shows only the partial release when the new quantity does not cover all waiting demand', function (): void {
    [$owner, $store] = sapWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Partial Preview Item', 'sku' => 'SAP-2', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));

    $order = Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'SAP-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 5, 'unit_price' => 50, 'line_total' => 250]],
    ]);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $response = $this->actingAs($owner)->postJson("/dashboard/stock/{$product->id}/preview-adjustment", [
        'warehouse_id' => $warehouse->id,
        'mode' => 'set',
        'quantity' => 2,
    ]);

    $response->assertOk()->assertJson([
        'waiting_demand' => 5,
        'releasable_waiting_units' => 2,
        'expected_on_hand' => 2,
        'expected_reserved' => 2,
        'expected_available' => 0,
    ]);
});

it('preview never creates an InventoryItem or balance row as a side effect', function (): void {
    [$owner, $store] = sapWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Untouched Item', 'sku' => 'SAP-3', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $this->actingAs($owner)->postJson("/dashboard/stock/{$product->id}/preview-adjustment", [
        'warehouse_id' => $warehouse->id,
        'mode' => 'set',
        'quantity' => 15,
    ])->assertOk()->assertJson([
        'current_on_hand' => 0,
        'expected_on_hand' => 15,
        'inventory_missing' => true,
    ]);

    expect(InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()->where('sku', 'SAP-3')->count()))->toBe(0)
        ->and(WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->count()))->toBe(0);
});

it('preview for a variant resolves its own item, never a stale product-level one', function (): void {
    [$owner, $store] = sapWorkspace();
    $warehouse = $store->getPrimaryWarehouse();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Preview Item', 'sku' => 'SAP-4', 'type' => 'variable', 'status' => 'active', 'price' => 65,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'SAP-4-S', 'price' => 65, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(\App\Services\Inventory\InventoryEngine::class)->setOnHand($item, $warehouse, 4, 'adjustment', null, $owner, 'Initial', false);

    $response = $this->actingAs($owner)->postJson("/dashboard/stock/{$product->id}/preview-adjustment", [
        'warehouse_id' => $warehouse->id,
        'variant_id' => $variant->id,
        'mode' => 'delta',
        'quantity' => 6,
    ]);

    $response->assertOk()->assertJson([
        'current_on_hand' => 4,
        'expected_on_hand' => 10,
        'inventory_missing' => false,
    ]);
});
