<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;

/**
 * Shopify orders are unique by (platform_connection_id, platform_order_id) —
 * OrderSyncService::saveOrder() is the single idempotent upsert used by
 * manual sync, scheduled sync, AND the webhook path, so a duplicate webhook
 * delivery can never create a duplicate order. X-Shopify-Webhook-Id gives a
 * second, faster layer of protection at the RECEIPT level (never even
 * re-running the mapper for a delivery already seen).
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function soiiWorkspace(string $name = 'Shopify Idempotency Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'consumer_key' => 'cid', 'consumer_secret' => 'soii-secret', 'status' => 'active',
    ]));

    return [$owner, $store, $connection];
}

function soiiHeaders(string $body, string $secret, string $topic, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

function soiiOrderPayload(string $id, string $total = '25.00'): array
{
    return [
        'id' => $id, 'order_number' => 5001, 'financial_status' => 'paid', 'current_total_price' => $total, 'currency' => 'USD',
        'customer' => ['first_name' => 'Idem', 'last_name' => 'Potent', 'email' => 'idem@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ];
}

it('does not create a duplicate order when the exact same webhook id is delivered twice', function (): void {
    [, $store, $connection] = soiiWorkspace();

    $body = json_encode(soiiOrderPayload('60001'));
    $headers = soiiHeaders($body, 'soii-secret', 'orders/create', $connection->shop_domain, 'wh-idem-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '60001')->count()))->toBe(1);

    expect(SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wh-idem-1')
        ->where('status', 'ignored_duplicate')
        ->count()))->toBe(2);
});

it('updates the same order in place — never a duplicate — when orders/updated arrives with a new webhook id', function (): void {
    [, $store, $connection] = soiiWorkspace('Update Not Duplicate Store');

    $createBody = json_encode(soiiOrderPayload('60002', '25.00'));
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}",
        server: soiiHeaders($createBody, 'soii-secret', 'orders/create', $connection->shop_domain, 'wh-idem-2'),
        content: $createBody)->assertOk();

    $updateBody = json_encode(soiiOrderPayload('60002', '55.00'));
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}",
        server: soiiHeaders($updateBody, 'soii-secret', 'orders/updated', $connection->shop_domain, 'wh-idem-3'),
        content: $updateBody)->assertOk();

    $orders = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '60002')->get());
    expect($orders)->toHaveCount(1)
        ->and((float) $orders->first()->total)->toBe(55.00);
});

it('never duplicates an order that a scheduled/manual sync already imported, when a webhook for the same order arrives afterwards', function (): void {
    [, $store, $connection] = soiiWorkspace('Cross-Path Idempotency Store');

    // Simulate the scheduled/manual sync path importing the order first —
    // the exact same idempotent saveOrder() the webhook path uses.
    app(OrderSyncService::class)->saveOrder([
        'platform_id' => '60003', 'number' => '#60003', 'status' => 'paid', 'total' => 40.0, 'currency' => 'USD',
        'customer_name' => 'Cross Path', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '60003')->count()))->toBe(1);

    $body = json_encode(soiiOrderPayload('60003', '40.00'));
    $headers = soiiHeaders($body, 'soii-secret', 'orders/create', $connection->shop_domain, 'wh-idem-4');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '60003')->count()))->toBe(1);
});
