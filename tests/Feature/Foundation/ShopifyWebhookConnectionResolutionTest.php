<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * The webhook URL is per-connection (/api/webhooks/shopify/{connection}),
 * but the X-Shopify-Shop-Domain header must still match that exact
 * connection's own shop_domain — otherwise a leaked/guessed connection id
 * could accept a webhook for a different shop entirely.
 */

/** @return array{0: User, 1: Store} */
function swcrBase(string $name): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function swcrConnection(Store $store, string $domain, string $secret): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => $domain,
        'consumer_key' => 'client-id',
        'consumer_secret' => $secret,
        'status' => 'active',
    ]));
}

function swcrHeaders(string $body, string $secret, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

function swcrOrderPayload(string $id): array
{
    return [
        'id' => $id, 'order_number' => 4001, 'financial_status' => 'paid', 'current_total_price' => '10.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Resolve', 'last_name' => 'Test', 'email' => 'resolve@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ];
}

it('resolves the correct connection by shop domain among several stores', function (): void {
    [, $storeA] = swcrBase('Resolution Store A');
    [, $storeB] = swcrBase('Resolution Store B');
    $connA = swcrConnection($storeA, 'resolution-a.myshopify.com', 'secret-a');
    swcrConnection($storeB, 'resolution-b.myshopify.com', 'secret-b');

    $body = json_encode(swcrOrderPayload('90101'));
    $headers = swcrHeaders($body, 'secret-a', 'resolution-a.myshopify.com', 'wh-resolve-1');

    $this->call('POST', "/api/webhooks/shopify/{$connA->id}", server: $headers, content: $body)
        ->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeA->id)->count()))->toBe(1)
        ->and(Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeB->id)->count()))->toBe(0);
});

it('rejects a webhook whose shop domain does not match this specific connection, even with a valid signature for a different shop', function (): void {
    [, $storeA] = swcrBase('Mismatch Store A');
    [, $storeB] = swcrBase('Mismatch Store B');
    $connA = swcrConnection($storeA, 'mismatch-a.myshopify.com', 'secret-a');
    swcrConnection($storeB, 'mismatch-b.myshopify.com', 'secret-b');

    // Signed correctly for connection A's OWN secret, but claiming to be
    // shop B's domain — must still be rejected, not silently resolved.
    $body = json_encode(swcrOrderPayload('90102'));
    $headers = swcrHeaders($body, 'secret-a', 'mismatch-b.myshopify.com', 'wh-resolve-2');

    $this->call('POST', "/api/webhooks/shopify/{$connA->id}", server: $headers, content: $body)
        ->assertStatus(403);

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_order_id', '90102')->exists()))->toBeFalse();
});

it('returns 404 for a webhook posted to an unknown connection id', function (): void {
    $body = json_encode(swcrOrderPayload('90103'));
    $headers = swcrHeaders($body, 'irrelevant', 'ghost.myshopify.com', 'wh-resolve-3');

    $this->call('POST', '/api/webhooks/shopify/01ARZ3NDEKTSV4RRFFQ69G5FAV', server: $headers, content: $body)
        ->assertStatus(404);
});

it('rejects a webhook for a connection that belongs to a different platform entirely', function (): void {
    [, $store] = swcrBase('Wrong Platform Store');
    $wooConnection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://wrong-platform.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));

    $body = json_encode(swcrOrderPayload('90104'));
    $headers = swcrHeaders($body, 'cs', 'wrong-platform.myshopify.com', 'wh-resolve-4');

    $this->call('POST', "/api/webhooks/shopify/{$wooConnection->id}", server: $headers, content: $body)
        ->assertStatus(403);
});
