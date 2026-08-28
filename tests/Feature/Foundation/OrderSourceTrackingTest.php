<?php

declare(strict_types=1);

use App\Enums\FulfillmentType;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Pos\OrderProcessingService;
use App\Services\Sync\OrderSyncService;
use App\Support\OrderSourceSummary;
use App\Events\OrderCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Phase OST2/OST4 — every order carries normalized, queryable source
 * metadata: source_type, source_platform, platform_connection_id, the
 * (already-existing) external order id/number, and human-readable store
 * labels. platform_connection_id + external_order_id (platform_order_id)
 * stay the authoritative online-order identity — source metadata is
 * additive, descriptive data alongside that identity, never a replacement
 * for it.
 */

/** @return array{0: User, 1: Store} */
function ostTrackWorkspace(string $name = 'Order Source Tracking Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ostTrackConnection(Store $store, string $platform, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id,
        'platform' => $platform,
        'status' => 'active',
        'label' => null,
    ], $overrides)));
}

/** Mirrors the shape BaseConnector::normalizeOrder() produces for any platform. */
function ostTrackNormalizedOrder(string $platformId, array $overrides = []): array
{
    return array_merge([
        'platform_id' => $platformId,
        'number' => 'REF-' . $platformId,
        'status' => 'processing',
        'total' => 200.0,
        'currency' => 'MAD',
        'customer_name' => 'Test Customer',
        'customer_email' => 'customer@example.com',
        'customer_phone' => '+212600000000',
        'items' => [['product_id' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 200]],
        'created_at' => now()->toIso8601String(),
        'platform_data' => ['id' => $platformId],
    ], $overrides);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('saves a POS order with source_type=pos and source_platform=pos', function (): void {
    [$owner, $store] = ostTrackWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'POS Source Product', 'sku' => 'OST-POS-1', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));
    $session = PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);

    $order = app(OrderProcessingService::class)->createOrder($store, $owner, [
        'pos_session_id' => $session->id,
        'fulfillment_type' => FulfillmentType::Instant->value,
        'payment_method' => 'cash',
        'total_amount' => 50,
        'amount_paid' => 50,
        'items' => [[
            'product_id' => $product->id, 'product_name' => $product->name,
            'unit_price' => 50, 'quantity' => 1, 'subtotal' => 50, 'line_total' => 50,
        ]],
    ]);

    // pos_orders has no source_* columns at all — a POS order's source is
    // deterministic (always "pos"/"pos") and its "store name" is already
    // available via the existing store relation, so nothing new to persist.
    // The presenter is what actually answers "source_type"/"source_platform".
    $summary = OrderSourceSummary::present($order->fresh());

    expect($summary['source_type'])->toBe('pos')
        ->and($summary['source_platform'])->toBe('pos')
        ->and($summary['badge_label'])->toBe('POS')
        ->and($summary['connection_label'])->toBe($store->name);
});

it('saves a Shopify order import with source_type=online, source_platform=shopify, connection id and external id', function (): void {
    [, $store] = ostTrackWorkspace();
    $connection = ostTrackConnection($store, 'shopify', ['shop_domain' => 'luminacare.myshopify.com', 'connection_method' => 'admin_token', 'access_token' => 'shpat_test']);

    $order = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('501001', ['number' => '#1042']), $connection);

    expect($order)->not->toBeNull()
        ->and($order->source_type)->toBe('online')
        ->and($order->source_platform)->toBe('shopify')
        ->and($order->platform_connection_id)->toBe($connection->id)
        ->and($order->platform_order_id)->toBe('501001') // the existing external_order_id equivalent
        ->and($order->order_number)->toBe('#1042')        // the existing external_order_number equivalent
        ->and($order->source_store_domain)->toBe('luminacare.myshopify.com')
        ->and($order->source_channel_label)->toBe('Shopify - luminacare.myshopify.com')
        ->and($order->organization_id)->toBe($store->organization_id)
        ->and($order->imported_at)->not->toBeNull();
});

it('saves a WooCommerce order import with source_type=online, source_platform=woocommerce, connection id and external id', function (): void {
    [, $store] = ostTrackWorkspace();
    $connection = ostTrackConnection($store, 'woocommerce', ['api_url' => 'https://shop.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']);

    $order = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('7778', ['number' => '1099']), $connection);

    expect($order)->not->toBeNull()
        ->and($order->source_type)->toBe('online')
        ->and($order->source_platform)->toBe('woocommerce')
        ->and($order->platform_connection_id)->toBe($connection->id)
        ->and($order->platform_order_id)->toBe('7778')
        ->and($order->order_number)->toBe('1099')
        ->and($order->source_store_domain)->toBe('shop.example.com')
        ->and($order->source_channel_label)->toBe('WooCommerce - shop.example.com');
});

it('saves a YouCan order import with source_type=online, source_platform=youcan', function (): void {
    [, $store] = ostTrackWorkspace();
    $connection = ostTrackConnection($store, 'youcan', ['api_url' => 'https://my-shop.youcan.shop', 'access_token' => 'yc_test']);

    $order = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('YC-9001', ['number' => 'YC-REF-9001']), $connection);

    expect($order)->not->toBeNull()
        ->and($order->source_type)->toBe('online')
        ->and($order->source_platform)->toBe('youcan')
        ->and($order->platform_connection_id)->toBe($connection->id)
        ->and($order->platform_order_id)->toBe('YC-9001')
        ->and($order->order_number)->toBe('YC-REF-9001')
        ->and($order->source_store_domain)->toBe('my-shop.youcan.shop');
});

it('falls back to the connection label (not the domain) when a YouCan connection has no api_url', function (): void {
    [, $store] = ostTrackWorkspace();
    $connection = ostTrackConnection($store, 'youcan', ['label' => 'My YouCan Store', 'api_url' => null]);

    $order = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('YC-9002'), $connection);

    expect($order->source_store_domain)->toBeNull()
        ->and($order->source_store_name)->toBe('My YouCan Store')
        ->and($order->source_channel_label)->toBe('YouCan'); // no domain to invent — platform label alone
});

it('updates the existing order (never a duplicate) when the same connection/external id is synced again', function (): void {
    [, $store] = ostTrackWorkspace();
    $connection = ostTrackConnection($store, 'woocommerce', ['api_url' => 'https://dup.example.com']);

    $first = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('9999'), $connection);
    $second = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('9999', ['total' => 999.0]), $connection);

    expect($second->id)->toBe($first->id)
        ->and(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $connection->id)->where('platform_order_id', '9999')->count()))->toBe(1);
});

it('allows the same external_order_id from two different platform_connection_ids', function (): void {
    [, $store] = ostTrackWorkspace();
    $shopify = ostTrackConnection($store, 'shopify', ['shop_domain' => 'shared-id.myshopify.com', 'connection_method' => 'admin_token', 'access_token' => 'shpat_test']);
    $woo = ostTrackConnection($store, 'woocommerce', ['api_url' => 'https://shared-id.example.com']);

    $orderA = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('SAME-ID'), $shopify);
    $orderB = app(OrderSyncService::class)->saveOrder(ostTrackNormalizedOrder('SAME-ID'), $woo);

    expect($orderA->id)->not->toBe($orderB->id)
        ->and(Order::withoutTenancy(fn () => Order::query()->where('platform_order_id', 'SAME-ID')->count()))->toBe(2);
});
