<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * The full acceptance scenario from the brief: a new Shopify order arrives
 * with NO connection profile ever opened and NO manual "Sync" click — only
 * a webhook delivery (or the scheduled fallback, covered separately in
 * ShopifyScheduledOrderImportTest) — and the order must show up exactly
 * where an online order always does: the unified Orders board and the
 * confirmation queue, in the same Pending/awaiting-confirmation state as
 * any other freshly-synced order, with no inventory consumed yet.
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function saoiWorkspace(string $name = 'Shopify Automatic Import Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'consumer_key' => 'cid', 'consumer_secret' => 'saoi-secret', 'status' => 'active',
    ]));

    return [$owner, $store, $connection];
}

function saoiHeaders(string $body, string $secret, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

it('a webhook-only Shopify order shows up automatically in the unified Orders board — no profile page ever opened', function (): void {
    [$owner, $store, $connection] = saoiWorkspace();

    $body = json_encode([
        'id' => '95001', 'order_number' => 7001, 'financial_status' => 'paid', 'current_total_price' => '120.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Auto', 'last_name' => 'Import', 'email' => 'auto@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $headers = saoiHeaders($body, 'saoi-secret', $connection->shop_domain, 'wh-e2e-1');

    // The ONLY thing that happens is Shopify calling the webhook — nobody
    // visits /dashboard/integrations/connections/{connection} at all.
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '95001')->first());
    expect($order)->not->toBeNull();

    $this->actingAs($owner)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders', fn ($orders) => collect($orders)->contains(fn ($o) => $o['id'] === $order->id)));
});

it('the same automatically-imported order appears in the confirmation queue', function (): void {
    [$owner, $store, $connection] = saoiWorkspace('Confirmation Queue Auto Import Store');

    $body = json_encode([
        'id' => '95002', 'order_number' => 7002, 'financial_status' => 'paid', 'current_total_price' => '80.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Queue', 'last_name' => 'Test', 'email' => 'queue@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $headers = saoiHeaders($body, 'saoi-secret', $connection->shop_domain, 'wh-e2e-2');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '95002')->firstOrFail());
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::Pending);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders', fn ($orders) => collect($orders)->contains(fn ($o) => $o['id'] === $order->id)));
});

it('does not consume/reserve inventory immediately for an automatically-imported pending order', function (): void {
    [, $store, $connection] = saoiWorkspace('No Inventory Consumption Store');

    $body = json_encode([
        'id' => '95003', 'order_number' => 7003, 'financial_status' => 'paid', 'current_total_price' => '45.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Stock', 'last_name' => 'Untouched', 'email' => 'stock@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $headers = saoiHeaders($body, 'saoi-secret', $connection->shop_domain, 'wh-e2e-3');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '95003')->firstOrFail());

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::Pending)
        ->and($order->inventoryAllocation)->toBeNull();
});

it('scopes automatically-imported orders to the correct store — never leaks into another tenant', function (): void {
    [$ownerA, $storeA, $connectionA] = saoiWorkspace('Scoped Store A');
    [$ownerB, $storeB] = saoiWorkspace('Scoped Store B');

    $body = json_encode([
        'id' => '95004', 'order_number' => 7004, 'financial_status' => 'paid', 'current_total_price' => '20.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Scope', 'last_name' => 'Test', 'email' => 'scope@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $headers = saoiHeaders($body, 'saoi-secret', $connectionA->shop_domain, 'wh-e2e-4');

    $this->call('POST', "/api/webhooks/shopify/{$connectionA->id}", server: $headers, content: $body)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeA->id)->where('platform_order_id', '95004')->exists()))->toBeTrue()
        ->and(Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeB->id)->where('platform_order_id', '95004')->exists()))->toBeFalse();

    $this->actingAs($ownerB)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders', fn ($orders) => collect($orders)->doesntContain(fn ($o) => $o['platform_connection_id'] === $connectionA->id)));
});
