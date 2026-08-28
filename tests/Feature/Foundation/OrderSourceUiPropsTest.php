<?php

declare(strict_types=1);

use App\Events\OrderCreated;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Phase OST5 — the Confirmation Desk queue and the order detail page both
 * receive the source badge/summary props (platform_label, connection_label,
 * store_domain, external_order_number, badge_label) built by
 * OrderSourceSummary, via OrderPresenter — never Livewire/Volt/Blade.
 */

/** @return array{0: User, 1: Store} */
function ostUiWorkspace(string $name = 'Order Source UI Props Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ostUiShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('gives the Confirmation Desk queue source badge/label props for each order', function (): void {
    [$owner, $store] = ostUiWorkspace();
    $connection = ostUiShopifyConnection($store, 'confdesk.myshopify.com');

    app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'CD-1001', 'number' => '#2001', 'status' => 'processing', 'total' => 150.0, 'currency' => 'MAD',
        'customer_name' => 'Jane Doe', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.source', 'online')
            ->where('orders.0.source_platform', 'shopify')
            ->where('orders.0.platform_label', 'Shopify')
            ->where('orders.0.connection_label', 'Shopify - confdesk.myshopify.com')
            ->where('orders.0.store_domain', 'confdesk.myshopify.com')
            ->where('orders.0.external_order_number', '#2001')
            ->where('orders.0.badge_label', 'Shopify'));
});

it('gives the online order detail page a full source summary', function (): void {
    [$owner, $store] = ostUiWorkspace();
    $connection = ostUiShopifyConnection($store, 'orderdetail.myshopify.com');

    $order = app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'CD-2002', 'number' => '#3003', 'status' => 'processing', 'total' => 200.0, 'currency' => 'MAD',
        'customer_name' => 'John Roe', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);

    $this->actingAs($owner)->get("/dashboard/orders/online/{$order->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Orders/ShowOnline')
            ->where('order.source_platform', 'shopify')
            ->where('order.platform_label', 'Shopify')
            ->where('order.store_domain', 'orderdetail.myshopify.com')
            ->where('order.external_order_number', '#3003')
            ->where('order.badge_label', 'Shopify'));
});

it('gives a POS order detail page a POS source summary too', function (): void {
    [$owner, $store] = ostUiWorkspace();
    $product = \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $store->id, 'name' => 'POS Detail Product', 'sku' => 'OSTUI-POS-1', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));
    $session = \App\Models\PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);
    $order = app(\App\Services\Pos\OrderProcessingService::class)->createOrder($store, $owner, [
        'pos_session_id' => $session->id, 'fulfillment_type' => 'instant', 'payment_method' => 'cash',
        'total_amount' => 40, 'amount_paid' => 40,
        'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 40, 'quantity' => 1, 'subtotal' => 40, 'line_total' => 40]],
    ]);

    $this->actingAs($owner)->get("/dashboard/orders/{$order->receipt_number}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Orders/Show')
            ->where('source.source_type', 'pos')
            ->where('source.source_platform', 'pos')
            ->where('source.badge_label', 'POS')
            ->where('source.connection_label', $store->name));
});
