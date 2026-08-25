<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| WooCommerce order webhooks — mirrors ShopifyWebhookTest.php's coverage
| for the new WooCommerceWebhookController: signature verification, site
| mismatch, idempotency, order creation, cross-store isolation, and the
| order.deleted topic being accepted but never mutating a local order.
|--------------------------------------------------------------------------
*/

function wowiWorkspace(string $name = 'WooCommerce Order Webhook Store', string $secret = 'wowi-secret'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $domain = 'https://' . strtolower(str_replace(' ', '-', $name)) . '.example.com';

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'pending',
        'api_url' => $domain, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        'webhook_secret' => $secret, 'webhook_status' => 'pending',
    ]));

    return [$owner, $store, $connection];
}

function wowiHeaders(string $body, string $secret, string $topic, string $source, string $deliveryId): array
{
    return [
        'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
        'HTTP_X_WC_WEBHOOK_SOURCE' => $source,
        'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $deliveryId,
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

function wowiOrderPayload(int $id = 7001, string $number = '7001'): array
{
    return [
        'id' => $id, 'number' => $number, 'status' => 'processing',
        'total' => '59.97', 'total_tax' => '0', 'shipping_total' => '0', 'discount_total' => '0',
        'currency' => 'MAD', 'payment_method' => 'cod',
        'billing' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'phone' => '+15550001111'],
        'line_items' => [
            ['id' => 1, 'product_id' => 501, 'variation_id' => 0, 'name' => 'Webhook Product', 'sku' => 'WC-WH-SKU-1', 'quantity' => 2, 'price' => '19.99', 'total' => '39.98'],
        ],
        'date_created' => now()->toIso8601String(),
    ];
}

it('rejects a webhook with an invalid signature', function (): void {
    [, , $connection] = wowiWorkspace();

    $body = json_encode(wowiOrderPayload());
    $headers = wowiHeaders($body, 'wrong-secret', 'order.created', $connection->api_url, 'wc-wh-1');

    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(401);

    expect(Order::withoutTenancy(fn () => Order::query()->count()))->toBe(0);

    $connection->refresh();
    expect($connection->webhook_status)->toBe('failed');
});

it('rejects a webhook whose site source does not match the connection', function (): void {
    [, , $connection] = wowiWorkspace();

    $body = json_encode(wowiOrderPayload());
    $headers = wowiHeaders($body, 'wowi-secret', 'order.created', 'https://someone-elses-site.example.com', 'wc-wh-2');

    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(403);

    expect(Order::withoutTenancy(fn () => Order::query()->count()))->toBe(0);
});

it('creates an Order with platform_connection set to the woocommerce connection', function (): void {
    [, $store, $connection] = wowiWorkspace();

    $body = json_encode(wowiOrderPayload());
    $headers = wowiHeaders($body, 'wowi-secret', 'order.created', $connection->api_url, 'wc-wh-3');

    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: $headers, content: $body)
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->first());

    expect($order)->not->toBeNull()
        ->and($order->platform_connection_id)->toBe($connection->id)
        ->and($order->platform_order_id)->toBe('7001')
        ->and($order->source_platform)->toBe('woocommerce')
        ->and($order->customer_phone)->toBe('+15550001111');

    $connection->refresh();
    expect($connection->webhook_status)->toBe('verified')
        ->and($connection->status)->toBe('active');
});

it('is idempotent for duplicate webhook deliveries', function (): void {
    [, $store, $connection] = wowiWorkspace();

    $body = json_encode(wowiOrderPayload(7002, '7002'));
    $headers = wowiHeaders($body, 'wowi-secret', 'order.created', $connection->api_url, 'wc-wh-dup-1');

    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: $headers, content: $body)->assertOk();
    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: $headers, content: $body)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->count()))->toBe(1);

    expect(SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wc-wh-dup-1')
        ->where('status', 'ignored_duplicate')
        ->exists()))->toBeTrue();
});

it('updates the same order (never duplicates) when order.updated arrives for it', function (): void {
    [, $store, $connection] = wowiWorkspace();

    $createBody = json_encode(wowiOrderPayload(7003, '7003'));
    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}",
        server: wowiHeaders($createBody, 'wowi-secret', 'order.created', $connection->api_url, 'wc-wh-u-1'),
        content: $createBody)->assertOk();

    $updated = wowiOrderPayload(7003, '7003');
    $updated['total'] = '99.99';
    $updateBody = json_encode($updated);
    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}",
        server: wowiHeaders($updateBody, 'wowi-secret', 'order.updated', $connection->api_url, 'wc-wh-u-2'),
        content: $updateBody)->assertOk();

    $orders = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->get());
    expect($orders)->toHaveCount(1)
        ->and((float) $orders->first()->total)->toBe(99.99);
});

it('accepts order.deleted without erroring and without creating or mutating any local order', function (): void {
    [, $store, $connection] = wowiWorkspace();

    $body = json_encode(wowiOrderPayload(7004, '7004'));
    $headers = wowiHeaders($body, 'wowi-secret', 'order.deleted', $connection->api_url, 'wc-wh-del-1');

    $this->call('POST', "/api/webhooks/woocommerce/{$connection->id}", server: $headers, content: $body)
        ->assertOk()
        ->assertJson(['status' => 'ignored_topic']);

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->count()))->toBe(0);
});

it('cannot let a webhook for one store write into another store\'s orders', function (): void {
    [, $storeA, $connectionA] = wowiWorkspace('WOWI Store A', 'secret-a');
    [, $storeB] = wowiWorkspace('WOWI Store B', 'secret-b');

    $body = json_encode(wowiOrderPayload(7005, '7005'));
    $headers = wowiHeaders($body, 'secret-a', 'order.created', $connectionA->api_url, 'wc-wh-cross-1');

    $this->call('POST', "/api/webhooks/woocommerce/{$connectionA->id}", server: $headers, content: $body)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeA->id)->count()))->toBe(1)
        ->and(Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeB->id)->count()))->toBe(0);
});
