<?php

declare(strict_types=1);

use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Pos\OrderProcessingService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Phase OST5/OST6 — the Orders index filters by source_type (pos/online),
 * source_platform (shopify/woocommerce/youcan/manual) and exact
 * platform_connection_id, all tenant-scoped to the acting store.
 */

/** @return array{0: User, 1: Store} */
function ostFilterWorkspace(string $name = 'Order Source Filtering Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ostFilterConnection(Store $store, string $platform, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id, 'platform' => $platform, 'status' => 'active',
    ], $overrides)));
}

function ostFilterOnlineOrder(PlatformConnection $connection, string $externalId): Order
{
    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "REF-{$externalId}", 'status' => 'processing',
        'total' => 100.0, 'currency' => 'MAD', 'customer_name' => 'Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

function ostFilterPosOrder(Store $store, User $cashier, Product $product): PosOrder
{
    $session = PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $cashier->id, 'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);

    return app(OrderProcessingService::class)->createOrder($store, $cashier, [
        'pos_session_id' => $session->id, 'fulfillment_type' => 'instant', 'payment_method' => 'cash',
        'total_amount' => 50, 'amount_paid' => 50,
        'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 50, 'quantity' => 1, 'subtotal' => 50, 'line_total' => 50]],
    ]);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('filters the orders index to POS only', function (): void {
    [$owner, $store] = ostFilterWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'P', 'sku' => 'OSF-1', 'type' => 'simple', 'status' => 'active', 'price' => 50]));
    ostFilterPosOrder($store, $owner, $product);
    $woo = ostFilterConnection($store, 'woocommerce', ['api_url' => 'https://osf1.example.com']);
    ostFilterOnlineOrder($woo, 'OSF-ONLINE-1');

    $this->actingAs($owner)->get('/dashboard/orders?source=pos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.origin', 'pos'));
});

it('filters the orders index to Online only', function (): void {
    [$owner, $store] = ostFilterWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'P', 'sku' => 'OSF-2', 'type' => 'simple', 'status' => 'active', 'price' => 50]));
    ostFilterPosOrder($store, $owner, $product);
    $woo = ostFilterConnection($store, 'woocommerce', ['api_url' => 'https://osf2.example.com']);
    ostFilterOnlineOrder($woo, 'OSF-ONLINE-2');

    $this->actingAs($owner)->get('/dashboard/orders?source=online')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.origin', 'online'));
});

it('filters the orders index by platform=shopify/woocommerce/youcan', function (): void {
    [$owner, $store] = ostFilterWorkspace();
    $shopify = ostFilterConnection($store, 'shopify', ['shop_domain' => 'osf3.myshopify.com', 'connection_method' => 'admin_token', 'access_token' => 'shpat_test']);
    $woo = ostFilterConnection($store, 'woocommerce', ['api_url' => 'https://osf3-woo.example.com']);
    $youcan = ostFilterConnection($store, 'youcan', ['api_url' => 'https://osf3.youcan.shop']);

    ostFilterOnlineOrder($shopify, 'SHOP-1');
    ostFilterOnlineOrder($woo, 'WOO-1');
    ostFilterOnlineOrder($youcan, 'YC-1');

    $this->actingAs($owner)->get('/dashboard/orders?platform=shopify')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', 'REF-SHOP-1'));

    $this->actingAs($owner)->get('/dashboard/orders?platform=woocommerce')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', 'REF-WOO-1'));

    $this->actingAs($owner)->get('/dashboard/orders?platform=youcan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', 'REF-YC-1'));
});

it('filters the orders index by an exact platform_connection_id', function (): void {
    [$owner, $store] = ostFilterWorkspace();
    $wooA = ostFilterConnection($store, 'woocommerce', ['api_url' => 'https://osf4-a.example.com']);

    // A second store, same owner, with its own WooCommerce connection — same
    // platform, different connection, must never be conflated.
    $storeB = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $store->organization_id, 'name' => 'Second Store']);
    $storeB->ensureDefaultRoles();
    $wooB = ostFilterConnection($storeB, 'woocommerce', ['api_url' => 'https://osf4-b.example.com']);

    ostFilterOnlineOrder($wooA, 'CONN-A-1');
    ostFilterOnlineOrder($wooB, 'CONN-B-1');

    $this->actingAs($owner)->get('/dashboard/orders?connection=' . $wooA->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', 'REF-CONN-A-1'));
});

it('never leaks another organization\'s orders through the source filters', function (): void {
    [$ownerA, $storeA] = ostFilterWorkspace('Merchant A');
    [$ownerB, $storeB] = ostFilterWorkspace('Merchant B');

    $wooA = ostFilterConnection($storeA, 'woocommerce', ['api_url' => 'https://merchant-a.example.com']);
    $wooB = ostFilterConnection($storeB, 'woocommerce', ['api_url' => 'https://merchant-b.example.com']);

    ostFilterOnlineOrder($wooA, 'MERCH-A-1');
    ostFilterOnlineOrder($wooB, 'MERCH-B-1');

    $this->actingAs($ownerA)->get('/dashboard/orders?platform=woocommerce')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', 'REF-MERCH-A-1'));

    // Merchant A must not reach Merchant B's connection id either, even by
    // guessing/pasting it directly into the connection filter.
    $this->actingAs($ownerA)->get('/dashboard/orders?connection=' . $wooB->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 0));
});
