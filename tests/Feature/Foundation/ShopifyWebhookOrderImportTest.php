<?php

declare(strict_types=1);

use App\Jobs\ShopifyOrderWebhookJob;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Queue;

/**
 * ShopifyWebhookController must return quickly: verify HMAC → resolve
 * connection → dispatch ShopifyOrderWebhookJob → return 200 immediately —
 * the actual order import happens in the job, off the request thread
 * (Shopify retries aggressively, and can eventually disable a webhook, on a
 * slow/non-2xx response). This file covers that queuing behavior
 * specifically; end-to-end creation/idempotency/HMAC coverage already lives
 * in ShopifyWebhookTest.php and the other new Shopify webhook test files.
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function swoitWorkspace(string $name = 'Shopify Webhook Order Import Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'consumer_key' => 'cid', 'consumer_secret' => 'swoit-secret', 'status' => 'active',
    ]));

    return [$owner, $store, $connection];
}

function swoitHeaders(string $body, string $secret, string $topic, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

function swoitOrderPayload(string $id): array
{
    return [
        'id' => $id, 'order_number' => 6001, 'financial_status' => 'paid', 'current_total_price' => '33.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Queue', 'last_name' => 'Job', 'email' => 'queuejob@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ];
}

it('dispatches ShopifyOrderWebhookJob for orders/create instead of importing inline', function (): void {
    [, , $connection] = swoitWorkspace();
    Queue::fake();

    $body = json_encode(swoitOrderPayload('50001'));
    $headers = swoitHeaders($body, 'swoit-secret', 'orders/create', $connection->shop_domain, 'wh-queue-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    Queue::assertPushed(ShopifyOrderWebhookJob::class, fn (ShopifyOrderWebhookJob $job) => $job->connectionId === $connection->id
        && $job->topic === 'orders/create');

    // The job never ran (queue faked) — nothing imported yet, proving the
    // controller itself does no order mapping on the request thread.
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_order_id', '50001')->exists()))->toBeFalse();
});

it('promotes the connection to verified/active immediately, even before the queued job runs', function (): void {
    [, , $connection] = swoitWorkspace('Immediate Promotion Store');
    Queue::fake();

    $body = json_encode(swoitOrderPayload('50002'));
    $headers = swoitHeaders($body, 'swoit-secret', 'orders/create', $connection->shop_domain, 'wh-queue-2');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $connection->refresh();
    expect($connection->webhook_status)->toBe('verified')
        ->and($connection->status)->toBe('active')
        ->and($connection->last_webhook_at)->not->toBeNull();
});

it('imports the order for real once the queued job actually runs', function (): void {
    [, $store, $connection] = swoitWorkspace('Job Runs For Real Store');

    // QUEUE_CONNECTION=sync in phpunit.xml — dispatch() runs the job inline.
    $body = json_encode(swoitOrderPayload('50003'));
    $headers = swoitHeaders($body, 'swoit-secret', 'orders/create', $connection->shop_domain, 'wh-queue-3');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '50003')->first());
    expect($order)->not->toBeNull();

    $log = SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wh-queue-3')
        ->first());
    expect($log->status)->toBe('processed');
});

it('skips a payload with no usable order id without creating anything, and still marks the log processed (not stuck)', function (): void {
    [, , $connection] = swoitWorkspace('Job No-Op Store');

    // saveOrder() treats an empty external id as "skip" (logged, not an
    // exception) — the job must not get stuck at 'verified' forever for a
    // no-op outcome.
    $body = json_encode(['id' => '', 'order_number' => 1, 'financial_status' => 'paid', 'current_total_price' => '0', 'currency' => 'USD', 'customer' => [], 'line_items' => [], 'created_at' => now()->toIso8601String()]);
    $headers = swoitHeaders($body, 'swoit-secret', 'orders/create', $connection->shop_domain, 'wh-queue-4');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $log = SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wh-queue-4')
        ->first());

    expect($log->status)->toBe('processed');
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $connection->id)->count()))->toBe(0);
});

it('marks the sync log failed (not silently swallowed) when the job itself throws while importing', function (): void {
    [, , $connection] = swoitWorkspace('Job Failure Store');

    // line_items must be an array — array_map() on a non-array throws a
    // TypeError inside ShopifyConnector::parseOrder(), a genuine mapper
    // failure the job must record, not swallow.
    $body = json_encode([
        'id' => '50004', 'order_number' => 6004, 'financial_status' => 'paid', 'current_total_price' => '10.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Bad', 'last_name' => 'Payload', 'email' => 'bad@example.com'],
        'line_items' => 'not-an-array', 'created_at' => now()->toIso8601String(),
    ]);
    $headers = swoitHeaders($body, 'swoit-secret', 'orders/create', $connection->shop_domain, 'wh-queue-5');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $log = SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wh-queue-5')
        ->first());

    expect($log->status)->toBe('failed')
        ->and($log->error_message)->not->toBeNull();
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_order_id', '50004')->exists()))->toBeFalse();
});
