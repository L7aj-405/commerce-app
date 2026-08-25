<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Orders\ReturnInspectionService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Queue;

/**
 * Phase O5 — return inspection through InventoryEngine (organization-backed
 * stores). Flagging a return moves no stock; only inspection disposition
 * does — resellable adds on_hand to the sellable (originally-allocated, or
 * primary) warehouse and queues an external sync since availability rose;
 * damaged adds on_hand to the damaged warehouse and must NOT queue a
 * sellable-stock sync (the damaged warehouse is not sellable); missing
 * moves nothing; a resubmitted disposition never double-restocks.
 */

/** @return array{0: User, 1: Store, 2: Warehouse} */
function rieMerchant(string $name = 'Return Inventory Engine Store'): array
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

function rieProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Return Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 80,
    ]));
}

/** Confirms + delivers an order so it can be returned, and returns the delivered Order. */
function rieDeliveredOrder(Store $store, Product $product, User $actor, int $qty = 2): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'RIE-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'total' => 80 * $qty,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $qty, 'unit_price' => 80, 'line_total' => 80 * $qty,
        ]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $actor);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Picking, $actor);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Packing, $actor);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::ReadyForDelivery, $actor);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Delivered, $actor);

    return $order->fresh();
}

function rieReturnFor(Order $order): OrderReturn
{
    return OrderReturn::query()
        ->where('returnable_type', Order::class)
        ->where('returnable_id', $order->id)
        ->open()
        ->firstOrFail()
        ->load('items');
}

beforeEach(function (): void {
    Queue::fake();
});

it('moves no stock when a return is opened', function (): void {
    [$owner, $store, $warehouse] = rieMerchant();
    $product = rieProduct($store, 'RIE-1');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Initial', false);
    $order = rieDeliveredOrder($store, $product, $owner, 2);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'wrong size');

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(3) // 5 - 2 consumed at dispatch, unchanged by the return flag
        ->and($balance->reserved)->toBe(0);
});

it('increases sellable on_hand and queues an external sync for a resellable disposition', function (): void {
    [$owner, $store, $warehouse] = rieMerchant();
    $product = rieProduct($store, 'RIE-2');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Initial', false);
    $order = rieDeliveredOrder($store, $product, $owner, 2);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'wrong size');
    $return = rieReturnFor($order->fresh());
    $item2 = $return->items->first();

    Queue::fake(); // clear pushes from the setup/dispatch above

    app(ReturnInspectionService::class)->disposition($return, [
        ['item_id' => $item2->id, 'condition' => 'resellable'],
    ], $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(5) // 3 + 2 restocked
        ->and($balance->available())->toBe(5);

    Queue::assertPushed(\App\Jobs\ExternalStockPushJob::class);
});

it('adds damaged units to the damaged warehouse and does not increase sellable availability', function (): void {
    [$owner, $store, $warehouse] = rieMerchant();
    $product = rieProduct($store, 'RIE-3');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Initial', false);
    $order = rieDeliveredOrder($store, $product, $owner, 2);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'damaged in transit');
    $return = rieReturnFor($order->fresh());
    $item2 = $return->items->first();

    Queue::fake();

    app(ReturnInspectionService::class)->disposition($return, [
        ['item_id' => $item2->id, 'condition' => 'damaged'],
    ], $owner);

    $sellableBalance = app(InventoryEngine::class)->balance($item, $warehouse);
    $damagedWarehouse = $store->getDamagedWarehouse();
    $damagedBalance = app(InventoryEngine::class)->balance($item, $damagedWarehouse);

    expect($sellableBalance->available())->toBe(3) // unchanged by the damaged disposition
        ->and($damagedBalance->on_hand)->toBe(2);

    Queue::assertNotPushed(\App\Jobs\ExternalStockPushJob::class);
});

it('moves no stock for a missing/lost disposition', function (): void {
    [$owner, $store, $warehouse] = rieMerchant();
    $product = rieProduct($store, 'RIE-4');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Initial', false);
    $order = rieDeliveredOrder($store, $product, $owner, 2);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'never arrived');
    $return = rieReturnFor($order->fresh());
    $item2 = $return->items->first();

    app(ReturnInspectionService::class)->disposition($return, [
        ['item_id' => $item2->id, 'condition' => 'missing'],
    ], $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(3)->and($balance->available())->toBe(3);
});

it('does not double-restock a return item dispositioned twice', function (): void {
    [$owner, $store, $warehouse] = rieMerchant();
    $product = rieProduct($store, 'RIE-5');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Initial', false);
    $order = rieDeliveredOrder($store, $product, $owner, 2);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'wrong size');
    $return = rieReturnFor($order->fresh());
    $item2 = $return->items->first();

    $inspection = app(ReturnInspectionService::class);
    $inspection->disposition($return, [['item_id' => $item2->id, 'condition' => 'resellable']], $owner);
    // Resubmitted form for the same line — must be a no-op.
    $inspection->disposition($return->fresh(), [['item_id' => $item2->id, 'condition' => 'resellable']], $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(5);
});

it('closes the return and completes the order only after every line is dispositioned', function (): void {
    [$owner, $store, $warehouse] = rieMerchant();
    $product = rieProduct($store, 'RIE-6');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'adjustment', null, null, 'Initial', false);
    $order = rieDeliveredOrder($store, $product, $owner, 2);

    $order = app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'wrong size');
    $return = rieReturnFor($order->fresh());
    $item2 = $return->items->first();

    $inspection = app(ReturnInspectionService::class);

    expect(fn () => $inspection->close($return, $owner))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    $inspection->disposition($return, [['item_id' => $item2->id, 'condition' => 'resellable']], $owner);
    $inspection->close($return->fresh(), $owner);

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReturnCompleted);
});
