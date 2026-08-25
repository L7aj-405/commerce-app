<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Cross-path idempotency: a webhook and a manual/queued sync must update
| the SAME local order, never create a second one — both ultimately call
| the same OrderSyncService::saveOrder(), keyed on
| (platform_connection_id, platform_order_id).
|--------------------------------------------------------------------------
*/

function owiWorkspace(string $name = 'Order Webhook Idempotency Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('a manual sync after a Shopify webhook updates the same order, never duplicates it', function (): void {
    [$owner, $store] = owiWorkspace();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'owi-shop.myshopify.com', 'webhook_secret' => 'owi-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));

    $webhookBody = json_encode([
        'id' => '99001', 'order_number' => 3001, 'financial_status' => 'paid', 'current_total_price' => '40.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Amy', 'last_name' => 'Lee', 'email' => 'amy@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: [
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $connection->shop_domain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'owi-wh-1',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $webhookBody, 'owi-secret', true)),
        'CONTENT_TYPE' => 'application/json',
    ], content: $webhookBody)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->firstOrFail());
    expect($order->platform_order_id)->toBe('99001');

    // Now the SAME order comes back through a manual "Sync orders now" pull
    // (higher total — the platform's own snapshot changed since the webhook).
    Http::fake(['*.myshopify.com/*' => Http::sequence()
        ->push(['orders' => [[
            'id' => '99001', 'order_number' => 3001, 'financial_status' => 'paid', 'current_total_price' => '65.00', 'currency' => 'USD',
            'customer' => ['first_name' => 'Amy', 'last_name' => 'Lee', 'email' => 'amy@example.com'],
            'line_items' => [], 'created_at' => now()->toIso8601String(),
        ]]], 200)
        ->push(['orders' => []], 200)]); // page 2 — stop the pull loop

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$connection->id}/sync-orders")
        ->assertOk();

    $orders = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->get());
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->id)->toBe($order->id)
        ->and((float) $orders->first()->total)->toBe(65.0);
});

it('a WooCommerce webhook after a manual sync updates the same order, never duplicates it', function (): void {
    [$owner, $store] = owiWorkspace();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://owi-woo.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        'webhook_secret' => 'owi-woo-secret',
    ]));

    Http::fake(['owi-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 99002, 'number' => '3002', 'status' => 'processing', 'total' => '30.00', 'currency' => 'MAD', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)]);

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$connection->id}/sync-orders")
        ->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '99002')->firstOrFail());

    $webhookBody = json_encode([
        'id' => 99002, 'number' => '3002', 'status' => 'processing', 'total' => '55.00', 'currency' => 'MAD',
        'billing' => ['first_name' => 'Amy', 'last_name' => 'Lee', 'email' => 'amy@example.com'],
        'line_items' => [], 'date_created' => now()->toIso8601String(),
    ]);
    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: [
        'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.updated',
        'HTTP_X_WC_WEBHOOK_SOURCE' => $connection->api_url,
        'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'owi-woo-wh-1',
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $webhookBody, 'owi-woo-secret', true)),
        'CONTENT_TYPE' => 'application/json',
    ], content: $webhookBody)->assertOk();

    $orders = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->get());
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->id)->toBe($order->id)
        ->and((float) $orders->first()->total)->toBe(55.0);
});
