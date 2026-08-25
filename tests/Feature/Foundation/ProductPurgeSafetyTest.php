<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\ProductCleanupSafetyService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store, 2: Warehouse} */
function pclSMerchant(string $name = 'Purge Safety Store'): array
{
    $user = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $store->ensureDefaultRoles();
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => $name . ' Warehouse', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA',
        'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $store, $warehouse];
}

function pclSProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => "Safety Product {$sku}", 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
}

function pclSPosOrder(Store $store, User $cashier): PosOrder
{
    $session = PosSession::withoutTenancy(fn () => PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $cashier->id, 'status' => 'open', 'opened_at' => now(),
    ]));

    return PosOrder::withoutTenancy(fn () => PosOrder::create([
        'store_id' => $store->id, 'pos_session_id' => $session->id, 'cashier_id' => $cashier->id,
        'receipt_number' => 'PSAFE-' . fake()->unique()->numerify('#####'), 'status' => 'completed',
        'total_amount' => 20,
    ]));
}

it('allows purging a product with no orders or history', function (): void {
    [, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-1');

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeTrue()
        ->and($check['blockers'])->toBe([]);
});

it('blocks purge when the product has a POS order line', function (): void {
    [$owner, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-2');
    $order = pclSPosOrder($store, $owner);
    PosOrderItem::create([
        'pos_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'product_sku' => $product->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'POS order line')))->toBeTrue();
});

it('blocks purge when the product has an online order line referencing it', function (): void {
    [, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-3');
    Order::factory()->create([
        'store_id' => $store->id, 'order_number' => 'SAFE-ORDER-1', 'total' => 20,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => 1, 'unit_price' => 20, 'line_total' => 20,
        ]],
    ]);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'online order line')))->toBeTrue();
});

it('blocks purge when the product has inventory ledger entries', function (): void {
    [, $store, $warehouse] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-4');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'seed', false);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 0, 'adjustment', null, null, 'zeroed out again', false);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'ledger')))->toBeTrue();
});

it('blocks purge when the product has a non-zero warehouse balance', function (): void {
    [, $store, $warehouse] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-5');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'seed', false);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'warehouse balance is not zero')))->toBeTrue();
});

it('blocks purge when the product has an active inventory reservation', function (): void {
    [, $store, $warehouse] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-6');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'seed', false);

    $allocation = \App\Models\InventoryAllocation::withoutOrganizationTenancy(fn () => \App\Models\InventoryAllocation::create([
        'organization_id' => $store->organization_id, 'warehouse_id' => $warehouse->id, 'status' => 'allocated',
    ]));
    \App\Models\InventoryReservation::withoutOrganizationTenancy(fn () => \App\Models\InventoryReservation::create([
        'organization_id' => $store->organization_id, 'allocation_id' => $allocation->id, 'inventory_item_id' => $item->id,
        'warehouse_id' => $warehouse->id, 'requested_quantity' => 2, 'reserved_quantity' => 2, 'status' => 'active',
    ]));

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'reservation')))->toBeTrue();
});

it('blocks purge when the product has a return line item', function (): void {
    [$owner, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-7');
    $return = OrderReturn::withoutTenancy(fn () => OrderReturn::create([
        'store_id' => $store->id, 'returnable_type' => \App\Models\PosOrder::class, 'returnable_id' => (string) \Illuminate\Support\Str::ulid(),
        'reference' => 'RET-SAFE-1', 'status' => 'awaiting_inspection', 'reason' => 'wrong_item', 'flagged_at' => now(),
    ]));
    OrderReturnItem::create([
        'order_return_id' => $return->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'product_sku' => $product->sku, 'quantity_ordered' => 1, 'quantity_returned' => 1,
    ]);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'return line item')))->toBeTrue();
});

it('blocks purge when the product has a stock transfer line item', function (): void {
    [$owner, $store, $warehouse] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-8');
    $transfer = StockTransfer::withoutTenancy(fn () => StockTransfer::create([
        'store_id' => $store->id, 'reference' => 'TRF-SAFE-1', 'source_warehouse_id' => $warehouse->id,
        'destination_kind' => StockTransfer::KIND_EXTERNAL, 'destination_label' => 'External', 'created_by' => $owner->id,
        'status' => StockTransfer::STATUS_COMPLETED, 'transfer_date' => now()->toDateString(), 'total_quantity' => 1,
    ]));
    StockTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'sku' => $product->sku, 'quantity' => 1,
    ]);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and(collect($check['blockers'])->contains(fn ($b) => str_contains($b, 'stock transfer line item')))->toBeTrue();
});

it('allows archive and unlink even when a product has history', function (): void {
    [$owner, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-9');
    $order = pclSPosOrder($store, $owner);
    PosOrderItem::create([
        'pos_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'product_sku' => $product->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_archive'])->toBeTrue()
        ->and($check['can_unlink'])->toBeTrue()
        ->and($check['can_purge'])->toBeFalse();
});

it('recommends adjusting stock to zero for a product blocked only by non-zero legacy stock', function (): void {
    [, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-10');

    // withoutEvents() bypasses InventoryCompatibilityBridge::fromLegacyStock()
    // — a normal Stock::create() always mirrors into the inventory engine and
    // would create a ledger entry too, which is a "history" blocker and
    // would defeat the point of isolating the stock-only case here.
    \App\Models\Stock::withoutTenancy(fn () => \App\Models\Stock::withoutEvents(fn () => \App\Models\Stock::create([
        'product_id' => $product->id, 'warehouse_id' => $store->getPrimaryWarehouse()->id, 'quantity' => 3, 'reorder_level' => 10,
    ])));

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeFalse()
        ->and($check['recommended_action'])->toBe(ProductCleanupSafetyService::ACTION_ADJUST_STOCK)
        ->and($check['recommended_action_label'])->toBe('Adjust stock to zero, then archive; purge will still be blocked if ledger exists');
});

it('does not set a recommended action when purge is allowed', function (): void {
    [, $store] = pclSMerchant();
    $product = pclSProduct($store, 'SAFE-11');

    $check = app(ProductCleanupSafetyService::class)->check($product);

    expect($check['can_purge'])->toBeTrue()
        ->and($check['recommended_action'])->toBeNull()
        ->and($check['recommended_action_label'])->toBeNull();
});
