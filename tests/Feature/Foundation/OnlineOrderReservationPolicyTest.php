<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\InventoryAllocation;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;

/**
 * Phase O2 — online order reservation policy. Default: a pending
 * (unconfirmed) online order does NOT reserve stock; only confirmation
 * does. config('inventory.reserve_online_pending_orders') opts into a soft
 * reservation the moment the order is imported — confirming it later must
 * never reserve a second time (WarehouseAllocationService::allocate() is
 * idempotent per order).
 */

/** @return array{0: User, 1: Store, 2: Warehouse} */
function oorpMerchant(string $name = 'Reservation Policy Store'): array
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

function oorpProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Reservation Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));
}

function oorpConnection(Store $store, string $externalId): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$externalId}.example.com", 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));
}

/** @return array{0: string, 1: string} [available, name] used to build a normalized platform order payload */
function oorpPlatformOrder(string $externalId, Product $product, int $qty = 3): array
{
    return [
        'platform_id' => $externalId,
        'number' => "#{$externalId}",
        'total' => 100,
        'currency' => 'MAD',
        'customer_name' => 'Jane Doe',
        'customer_email' => null,
        'customer_phone' => null,
        'items' => [[
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => $qty,
            'unit_price' => 50,
            'line_total' => 50 * $qty,
        ]],
    ];
}

afterEach(function (): void {
    config(['inventory.reserve_online_pending_orders' => false]);
});

it('does not reserve stock for a new pending online order by default', function (): void {
    config(['inventory.reserve_online_pending_orders' => false]);
    [$owner, $store, $warehouse] = oorpMerchant();
    $product = oorpProduct($store, 'OORP-1');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    $connection = oorpConnection($store, 'oorp-1');
    app(OrderSyncService::class)->saveOrder(oorpPlatformOrder('oorp-1', $product), $connection);

    expect(InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->count()))->toBe(0)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->available())->toBe(10);
});

it('reserves stock for a new pending online order when the optional flag is enabled', function (): void {
    config(['inventory.reserve_online_pending_orders' => true]);
    [$owner, $store, $warehouse] = oorpMerchant();
    $product = oorpProduct($store, 'OORP-2');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    $connection = oorpConnection($store, 'oorp-2');
    $order = app(OrderSyncService::class)->saveOrder(oorpPlatformOrder('oorp-2', $product, 4), $connection);

    expect(InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->count()))->toBe(1)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->available())->toBe(6)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->on_hand)->toBe(10)
        ->and($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
});

it('confirms exactly once — a soft-reserved pending order is not reserved a second time on confirmation', function (): void {
    config(['inventory.reserve_online_pending_orders' => true]);
    [$owner, $store, $warehouse] = oorpMerchant();
    $product = oorpProduct($store, 'OORP-3');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    $connection = oorpConnection($store, 'oorp-3');
    $order = app(OrderSyncService::class)->saveOrder(oorpPlatformOrder('oorp-3', $product, 4), $connection);

    app(OrderWorkflowService::class)->transition($order->fresh(), FulfillmentStatus::Confirmed, $owner);

    expect(InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->count()))->toBe(1)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->available())->toBe(6);
});

it('releases the soft reservation when a pending order is cancelled before confirmation', function (): void {
    config(['inventory.reserve_online_pending_orders' => true]);
    [$owner, $store, $warehouse] = oorpMerchant();
    $product = oorpProduct($store, 'OORP-4');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    $connection = oorpConnection($store, 'oorp-4');
    $order = app(OrderSyncService::class)->saveOrder(oorpPlatformOrder('oorp-4', $product, 4), $connection);

    app(OrderWorkflowService::class)->transition($order->fresh(), FulfillmentStatus::Cancelled, $owner, 'customer changed mind');

    expect(app(InventoryEngine::class)->balance($item, $warehouse)->available())->toBe(10)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->on_hand)->toBe(10);
});

it('does not double-reserve when the same platform order is synced/webhooked again while still pending', function (): void {
    config(['inventory.reserve_online_pending_orders' => true]);
    [$owner, $store, $warehouse] = oorpMerchant();
    $product = oorpProduct($store, 'OORP-5');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    $connection = oorpConnection($store, 'oorp-5');
    app(OrderSyncService::class)->saveOrder(oorpPlatformOrder('oorp-5', $product, 4), $connection);
    // A repeated poll/webhook for the SAME external order id — saveOrder()'s
    // existing-order branch never re-reserves (it only refreshes volatile fields).
    app(OrderSyncService::class)->saveOrder(oorpPlatformOrder('oorp-5', $product, 4), $connection);

    expect(InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->count()))->toBe(1)
        ->and(app(InventoryEngine::class)->balance($item, $warehouse)->available())->toBe(6);
});
