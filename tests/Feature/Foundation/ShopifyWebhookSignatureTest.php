<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * The root-cause fix: a Shopify connection using admin_client_credentials
 * (or admin_token) — the connection method "Sync" actually uses — must be
 * able to receive and verify webhooks. Before this fix,
 * ShopifyWebhookController required connection_method === 'webhook', a
 * completely separate, credential-less setup, so a normal sync-capable
 * connection could never receive webhooks at all. See
 * PlatformConnection::effectiveWebhookSecret(): for admin_client_credentials,
 * Shopify signs webhooks with the app's client secret (consumer_secret),
 * so no extra webhook_secret needs to be configured.
 */

/** @return array{0: User, 1: Store} */
function swstBase(string $name): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function swstWorkspace(string $name = 'Shopify Signature Store', string $clientSecret = 'swst-client-secret'): array
{
    [$owner, $store] = swstBase($name);

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'admin_client_credentials',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'consumer_key' => 'swst-client-id',
        'consumer_secret' => $clientSecret,
        'status' => 'active',
    ]));

    return [$owner, $store, $connection];
}

function swstHeaders(string $body, string $secret, string $topic, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

function swstOrderPayload(string $id = '80001'): array
{
    return [
        'id' => $id, 'order_number' => 3001, 'financial_status' => 'paid', 'current_total_price' => '29.99', 'currency' => 'USD',
        'customer' => ['first_name' => 'Amal', 'last_name' => 'B', 'email' => 'amal@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ];
}

it('accepts a correctly-signed webhook for an admin_client_credentials connection (the real fix)', function (): void {
    [, $store, $connection] = swstWorkspace();

    $body = json_encode(swstOrderPayload());
    $headers = swstHeaders($body, 'swst-client-secret', 'orders/create', $connection->shop_domain, 'wh-accc-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '80001')->exists()))->toBeTrue();
});

it('rejects an invalid HMAC signature for an admin_client_credentials connection with 401', function (): void {
    [, , $connection] = swstWorkspace();

    $body = json_encode(swstOrderPayload('80002'));
    $headers = swstHeaders($body, 'totally-wrong-secret', 'orders/create', $connection->shop_domain, 'wh-accc-2');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(401);

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_order_id', '80002')->exists()))->toBeFalse();

    $connection->refresh();
    expect($connection->webhook_status)->toBe('failed');
});

it('rejects webhooks for a Shopify connection with no usable secret at all (admin_token, no webhook_secret set)', function (): void {
    [, $store] = swstBase('No Secret Store');
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'shop_domain' => 'no-secret-store.myshopify.com', 'access_token' => 'shpat_test', 'status' => 'active',
    ]));

    $body = json_encode(swstOrderPayload('80003'));
    // Even a "correctly" signed body (against a guessed secret) can't pass —
    // there's no consumer_secret/webhook_secret on this connection at all.
    $headers = swstHeaders($body, 'anything', 'orders/create', $connection->shop_domain, 'wh-no-secret-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(403);

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_order_id', '80003')->exists()))->toBeFalse();
});

it('still rejects an invalid signature for the dedicated webhook connection method (unchanged)', function (): void {
    [, $store] = swstBase('Dedicated Webhook Store');
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'dedicated-webhook-store.myshopify.com', 'webhook_secret' => 'dedicated-secret',
        'status' => 'pending', 'webhook_status' => 'pending',
    ]));

    $body = json_encode(swstOrderPayload('80004'));
    $headers = swstHeaders($body, 'wrong-one', 'orders/create', $connection->shop_domain, 'wh-dedicated-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(401);
});
