<?php

declare(strict_types=1);

use App\Models\InventoryLedgerEntry;
use App\Models\Product;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Models\WarehouseInventoryBalance;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function adjustStockWorkspace(string $name = 'Adjust Stock Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('updates WarehouseInventoryBalance for a simple product stock adjustment', function (): void {
    [$owner, $store] = adjustStockWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Adjust Simple', 'sku' => 'ADJ-SIMPLE-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 25, 'reason' => 'Initial count',
    ])->assertSessionHasNoErrors();

    $item = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(25);
});

it('writes an InventoryLedgerEntry for a stock adjustment', function (): void {
    [$owner, $store] = adjustStockWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Adjust Ledger', 'sku' => 'ADJ-LEDGER-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 30, 'reason' => 'Initial count',
    ])->assertSessionHasNoErrors();

    $item = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;

    expect(InventoryLedgerEntry::withoutOrganizationTenancy(fn () => InventoryLedgerEntry::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->exists()))->toBeTrue();
});

it('adjusts stock for a specific variant via variant_id', function (): void {
    [$owner, $store] = adjustStockWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Adjust Variant', 'sku' => 'ADJ-VAR-1', 'type' => 'variable', 'status' => 'active', 'price' => 60,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'ADJ-VAR-1-S', 'price' => 60, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $warehouse = $store->getPrimaryWarehouse();

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 12, 'reason' => 'Initial count', 'variant_id' => $variant->id,
    ])->assertSessionHasNoErrors();

    $item = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $variant->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(12)
        ->and(InventoryLedgerEntry::withoutOrganizationTenancy(fn () => InventoryLedgerEntry::query()->where('inventory_item_id', $item->id)->exists()))->toBeTrue();
});

it('rejects a variant_id that belongs to another product/store', function (): void {
    [, $storeA] = adjustStockWorkspace('Adjust Store A');
    [$ownerB, $storeB] = adjustStockWorkspace('Adjust Store B');

    $productA = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $storeA->id, 'name' => 'Store A Variable', 'sku' => 'ADJ-CROSS-A', 'type' => 'variable', 'status' => 'active', 'price' => 60,
    ]));
    app(ProductVariantWizardService::class)->sync($productA, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'ADJ-CROSS-A-S', 'price' => 60, 'options' => ['Size' => 'S']],
    ]);
    $variantA = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $productA->id)->firstOrFail());

    $productB = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $storeB->id, 'name' => 'Store B Simple', 'sku' => 'ADJ-CROSS-B', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $warehouseB = $storeB->getPrimaryWarehouse();

    // Owner B tries to adjust product B's stock but passes store A's variant id.
    $this->actingAs($ownerB)->post("/dashboard/products/{$productB->id}/stock", [
        'warehouse_id' => $warehouseB->id, 'type' => 'set_on_hand', 'quantity' => 5, 'reason' => 'Attempted cross-store adjust', 'variant_id' => $variantA->id,
    ])->assertNotFound();

    expect(VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $variantA->id)->exists()))->toBeFalse();
});

it('cannot adjust stock for a variant that has not been saved yet', function (): void {
    [$owner, $store] = adjustStockWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Adjust Unsaved', 'sku' => 'ADJ-UNSAVED-1', 'type' => 'variable', 'status' => 'active', 'price' => 60,
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    // No variant has been persisted yet — there is no id to send, exactly
    // the state the "Save product first to adjust stock." UI message covers.
    // A client-generated placeholder id must not be adjustable.
    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 5, 'reason' => 'Should fail', 'variant_id' => '01HNOTREALVARIANTID0000000',
    ])->assertNotFound();
});
