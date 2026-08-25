<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;

/**
 * Root-cause regression coverage for the reported bug: a waiting order's
 * variant stock was topped up but "Recheck stock" never released it because
 * the shortage's InventoryReservation had been resolved against the parent
 * product's (stale, pre-variant) InventoryItem instead of the variant's own
 * one — see CatalogInventoryService::resolve() and
 * WarehouseAllocationService::repairReservationItem().
 */

/** @return array{0: User, 1: Store} */
function wsvmWorkspace(string $name = 'Waiting Stock Variant Mapping Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function wsvmVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Waiting Variant Item', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 90,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 90, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 92, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

function wsvmVariantOrder(Store $store, Product $product, ProductVariant $variant, int $qty): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'WSVM-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'items' => [[
            'product_id' => $product->id, 'variant_id' => $variant->id, 'name' => $product->name, 'sku' => $variant->sku,
            'quantity' => $qty, 'unit_price' => 90, 'line_total' => 90 * $qty,
        ]],
    ]);
}

it('resolves a variant order line to the variant\'s own inventory item, never a stale product-level link', function (): void {
    // Unit-style: exercises CatalogInventoryService::resolve() directly —
    // the exact call WarehouseAllocationService::requirements() makes per
    // order line (via resolveOrderLine()) — without going through
    // allocate(), so this test doesn't need a warehouse at all. Allocation
    // (and its own warehouse-candidate validation) is covered separately by
    // the other tests in this file and by WaitingStockReallocationTest.
    [, $store] = wsvmWorkspace();
    $product = wsvmVariableProduct($store, 'WSVM-1');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSVM-1-S')->firstOrFail());

    // Simulate a product that already had a product-level inventory link
    // before it became variable (or from an earlier unmapped order line
    // that matched at product level) — this is the exact condition the bug
    // needs to manifest under.
    $staleItem = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::create([
        'organization_id' => $store->organization_id, 'sku' => 'WSVM-1-STALE', 'name' => 'Stale product-level item', 'is_active' => true,
    ]));
    ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::create([
        'organization_id' => $store->organization_id, 'inventory_item_id' => $staleItem->id, 'product_id' => $product->id, 'units_per_sale' => 1,
    ]));

    // The variant itself has no VariantInventoryLink yet — resolve() must
    // create/resolve the variant's OWN item here, never fall back to the
    // product-level link above.
    $resolved = app(CatalogInventoryService::class)->resolve($product->id, $variant->id);

    $variantLink = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()
        ->where('product_variant_id', $variant->id)->first());

    expect($resolved)->not->toBeNull()
        ->and($variantLink)->not->toBeNull()
        ->and($resolved->id)->toBe($variantLink->inventory_item_id)
        ->and($resolved->id)->not->toBe($staleItem->id);
});

it('auto-releases a waiting order to Pick & Pack when the correct variant\'s stock is set via the inventory-safe endpoint', function (): void {
    [$owner, $store] = wsvmWorkspace();
    $product = wsvmVariableProduct($store, 'WSVM-2');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSVM-2-S')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $order = wsvmVariantOrder($store, $product, $variant, 1);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id,
        'type' => 'set_on_hand',
        'quantity' => 10,
        'reason' => 'Restock',
        'variant_id' => $variant->id,
    ])->assertSessionHasNoErrors();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);
});

it('recheck stock repairs a mis-resolved shortage reservation and releases the order', function (): void {
    [$owner, $store] = wsvmWorkspace();
    $product = wsvmVariableProduct($store, 'WSVM-3');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSVM-3-S')->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $order = wsvmVariantOrder($store, $product, $variant, 1);
    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    // Correctly resolves to the variant's own item post-fix.
    $correctItem = app(CatalogInventoryService::class)->forCatalog($product, $variant);

    // Force the reservation back into the historical broken state (as if it
    // had been created before the resolve() fix), pointing at an unrelated
    // stale item with nothing reserved yet.
    $staleItem = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::create([
        'organization_id' => $store->organization_id, 'sku' => 'WSVM-3-STALE', 'name' => 'Stale item', 'is_active' => true,
    ]));
    $reservation = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
        ->whereHas('allocation', fn ($q) => $q->where('source_id', $order->id))->firstOrFail());
    expect($reservation->reserved_quantity)->toBe(0);
    $reservation->update(['inventory_item_id' => $staleItem->id]);

    // Stock lands on the CORRECT item/warehouse — the corrupted reservation
    // still points elsewhere, so nothing should auto-resolve yet.
    app(InventoryEngine::class)->setOnHand($correctItem, $warehouse, 10, 'adjustment', null, $owner, 'Restock', false);
    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock);

    $response = $this->actingAs($owner)->post("/dashboard/operations/waiting-stock/online/{$order->id}/recheck");
    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'repaired'));

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);
    expect($reservation->fresh()->inventory_item_id)->toBe($correctItem->id);
});

it('recheck stock gives a clear reason instead of a silent no-op when still genuinely blocked', function (): void {
    [$owner, $store] = wsvmWorkspace();
    $product = wsvmVariableProduct($store, 'WSVM-4');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WSVM-4-S')->firstOrFail());
    $store->getPrimaryWarehouse();

    $order = wsvmVariantOrder($store, $product, $variant, 2);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)
        ->post("/dashboard/operations/waiting-stock/online/{$order->id}/recheck")
        ->assertSessionHas('warning', fn ($message) => str_contains($message, 'No available stock anywhere'));
});
