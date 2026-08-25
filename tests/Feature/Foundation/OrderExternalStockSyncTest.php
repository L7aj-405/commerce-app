<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Jobs\ExternalStockPushJob;
use App\Models\InventoryAdjustment;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Phase O6 — every order/POS/return event that changes SELLABLE available
 * stock must queue the canonical external push (ExternalStockPushJob ->
 * ProductPushService: Shopify via InventoryLevel absolute-set,
 * WooCommerce via stock_quantity), never the old ad-hoc
 * delta-adjust/product-update payload — and a platform failure must never
 * roll back the already-committed local inventory.
 */

/** @return array{0: User, 1: Store, 2: Warehouse} */
function oesMerchant(string $name = 'Order External Sync Store'): array
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

function oesShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test', 'metadata' => ['location_id' => '900555'],
    ]));
}

function oesWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));
}

function oesProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'External Sync Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));
}

function oesOrder(Store $store, Product $product, int $qty = 2): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'OES-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'total' => 40 * $qty,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => $qty, 'unit_price' => 40, 'line_total' => 40 * $qty,
        ]],
    ]);
}

it('queues an external stock sync when confirming an online order reserves stock (available decreases)', function (): void {
    Queue::fake();
    [$owner, $store, $warehouse] = oesMerchant();
    $product = oesProduct($store, 'OES-1');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $order = oesOrder($store, $product, 3);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    Queue::assertPushed(ExternalStockPushJob::class, fn (ExternalStockPushJob $job) => $job->productId === $product->id);
});

it('pushes the correct absolute available quantity to Shopify via InventoryLevel when a job actually runs', function (): void {
    [$owner, $store, $warehouse] = oesMerchant();
    $shopify = oesShopifyConnection($store, 'oes2-shop.myshopify.com');
    $product = oesProduct($store, 'OES-2');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'oes-2-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '70002'],
    ]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    Http::fake(['oes2-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 7]], 200)]);

    $order = oesOrder($store, $product, 3);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json')
        && $r['available'] === 7
        && $r['inventory_item_id'] === '70002'
        && $r['location_id'] === '900555');
});

it('pushes stock_quantity to WooCommerce when a job actually runs', function (): void {
    [$owner, $store, $warehouse] = oesMerchant();
    $woo = oesWooConnection($store, 'oes3-woo.example.com');
    $product = oesProduct($store, 'OES-3');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'oes-3-remote', 'sync_status' => 'synced',
    ]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    Http::fake(['oes3-woo.example.com/wp-json/wc/v3/products/oes-3-remote' => Http::response(['id' => 'oes-3-remote'], 200)]);

    $order = oesOrder($store, $product, 4);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    Http::assertSent(fn ($r) => $r->method() === 'PUT'
        && str_contains($r->url(), '/products/oes-3-remote')
        && $r['stock_quantity'] === 6);
});

it('queues a sync on release when a confirmed order is cancelled (available rises again)', function (): void {
    Queue::fake();
    [$owner, $store, $warehouse] = oesMerchant();
    $product = oesProduct($store, 'OES-4');
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);
    $order = oesOrder($store, $product, 3);

    $workflow = app(OrderWorkflowService::class);
    $order = $workflow->transition($order, FulfillmentStatus::Confirmed, $owner);

    Queue::fake(); // isolate the cancellation's own dispatch

    $workflow->transition($order->fresh(), FulfillmentStatus::Cancelled, $owner, 'changed mind');

    Queue::assertPushed(ExternalStockPushJob::class);
});

it('never rolls back local inventory when the external platform push fails', function (): void {
    [$owner, $store, $warehouse] = oesMerchant();
    $shopify = oesShopifyConnection($store, 'oes5-shop.myshopify.com');
    $product = oesProduct($store, 'OES-5');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'oes-5-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '70005'],
    ]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 10, 'adjustment', null, null, 'Initial', false);

    Http::fake(['oes5-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'boom'], 500)]);

    $order = oesOrder($store, $product, 3);
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(10)
        ->and($balance->reserved)->toBe(3)
        ->and($balance->available())->toBe(7);

    $adjustment = InventoryAdjustment::query()->where('product_id', $product->id)->latest()->first();
    expect($adjustment->sync_status)->toBe('failed');
});
