<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use App\Services\Pos\OrderProcessingService;
use Illuminate\Support\Facades\Queue;

/**
 * Phase O3 — POS stock semantics via InventoryEngine (organization-backed
 * stores). Instant sale consumes on_hand immediately at checkout; delivery
 * only reserves at checkout and consumes at ready_for_delivery, exactly
 * mirroring the online-order Pending -> Confirmed -> ready_for_delivery
 * flow. Non-organization stores keep the legacy direct-Stock-write path,
 * covered separately by PosDeliveryRoutingTest.
 */

/** @return array{0: User, 1: Store, 2: Warehouse} */
function posivMerchant(string $name = 'Pos Inventory Store'): array
{
    $user = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'hybrid', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
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

function posivProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'POS Inventory Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 60,
    ]));
}

function posivSession(Store $store, User $cashier): PosSession
{
    return PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $cashier->id, 'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);
}

/** @return array{0: string, 1: string} */
function posivCheckoutData(PosSession $session, Product $product, string $fulfillmentType, int $qty = 1): array
{
    return [
        'pos_session_id' => $session->id,
        'fulfillment_type' => $fulfillmentType,
        'delivery_address' => $fulfillmentType === 'delivery' ? '1 Test Street' : null,
        'payment_method' => 'cash',
        'total_amount' => 60 * $qty,
        'amount_paid' => 60 * $qty,
        'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name,
            'unit_price' => 60, 'quantity' => $qty, 'subtotal' => 60 * $qty, 'line_total' => 60 * $qty,
        ]],
    ];
}

beforeEach(function (): void {
    Queue::fake();
});

it('decreases on_hand immediately for a POS instant sale', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-1');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'instant', 2));
    $service->adjustInventory($order, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);

    expect($balance->on_hand)->toBe(8)
        ->and($balance->reserved)->toBe(0)
        ->and($balance->available())->toBe(8)
        ->and($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Completed);
});

it('queues an external stock sync for a POS instant sale', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-2');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'instant', 1));
    $service->adjustInventory($order, $owner);

    Queue::assertPushed(\App\Jobs\ExternalStockPushJob::class);
});

it('reserves stock but does not decrease on_hand for a POS delivery order at checkout', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-3');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'delivery', 3));
    $service->adjustInventory($order, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);

    expect($balance->on_hand)->toBe(10)
        ->and($balance->reserved)->toBe(3)
        ->and($balance->available())->toBe(7)
        ->and($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);
});

it('consumes the reservation when a POS delivery order reaches ready_for_delivery', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-4');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'delivery', 3));
    $service->adjustInventory($order, $owner);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Picking, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Packing, $owner);
    $workflow->transition($order->fresh(), FulfillmentStatus::ReadyForDelivery, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);

    expect($balance->on_hand)->toBe(7)
        ->and($balance->reserved)->toBe(0)
        ->and($balance->available())->toBe(7);
});

it('releases the reservation when a POS delivery order is cancelled before dispatch', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-5');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'delivery', 3));
    $service->adjustInventory($order, $owner);

    app(OrderWorkflowService::class)->transition($order->fresh(), FulfillmentStatus::Cancelled, $owner, 'customer cancelled');

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);

    expect($balance->on_hand)->toBe(10)
        ->and($balance->reserved)->toBe(0)
        ->and($balance->available())->toBe(10);
});

it('does not deduct stock a second time as a POS delivery order moves through picking/packing', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-6');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'delivery', 3));
    $service->adjustInventory($order, $owner);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Picking, $owner);
    $order = $workflow->transition($order->fresh(), FulfillmentStatus::Packing, $owner);

    // Still just reserved — packing itself must never touch on_hand.
    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(10)->and($balance->reserved)->toBe(3);

    $workflow->transition($order->fresh(), FulfillmentStatus::ReadyForDelivery, $owner);
    $workflow->transition($order->fresh()->refresh(), FulfillmentStatus::Delivered, $owner);
    $workflow->transition($order->fresh()->refresh(), FulfillmentStatus::Completed, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(7)->and($balance->reserved)->toBe(0);
});

it('never oversells a POS instant sale beyond available stock', function (): void {
    [$owner, $store, $warehouse] = posivMerchant();
    $product = posivProduct($store, 'POSIV-7');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 1, 'adjustment', null, null, 'Initial', false);
    $session = posivSession($store, $owner);

    $service = app(OrderProcessingService::class);
    $order = $service->createOrder($store, $owner, posivCheckoutData($session, $product, 'instant', 5));

    expect(fn () => $service->adjustInventory($order, $owner))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(app(InventoryEngine::class)->balance($item, $warehouse)->on_hand)->toBe(1);
});
