<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\OrganizationProvisioner;

function shopifyWebhookWorkspace(string $name = 'Shopify Webhook Store', string $secret = 'test-webhook-secret'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'webhook',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'webhook_secret' => $secret,
        'status' => 'pending',
        'webhook_status' => 'pending',
        'settings' => ['webhook_events' => ['orders/create', 'orders/updated', 'products/create', 'products/update']],
    ]));

    return [$owner, $store, $connection];
}

function shopifyWebhookHeaders(string $body, string $secret, string $topic, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

function shopifyProductPayload(string $id = '9001', string $sku = 'WH-SKU-1'): array
{
    return [
        'id' => $id,
        'title' => 'Webhook Product',
        'body_html' => '<p>desc</p>',
        'status' => 'active',
        'variants' => [
            ['id' => 5001, 'sku' => $sku, 'price' => '19.99', 'compare_at_price' => null, 'option1' => null, 'option2' => null, 'option3' => null, 'inventory_quantity' => 5],
        ],
        'images' => [],
    ];
}

function shopifyOrderPayload(string $id = '77001'): array
{
    return [
        'id' => $id,
        'order_number' => 1001,
        'financial_status' => 'paid',
        'current_total_price' => '59.97',
        'currency' => 'USD',
        'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'phone' => null],
        'shipping_address' => ['phone' => '+15550001111'],
        'line_items' => [
            ['id' => 1, 'product_id' => '9001', 'variant_id' => '5001', 'name' => 'Webhook Product', 'sku' => 'WH-SKU-1', 'quantity' => 2, 'price' => '19.99'],
        ],
        'created_at' => now()->toIso8601String(),
    ];
}

it('rejects a webhook with an invalid HMAC signature', function (): void {
    [, , $connection] = shopifyWebhookWorkspace();

    $body = json_encode(shopifyProductPayload());
    $headers = shopifyWebhookHeaders($body, 'wrong-secret', 'products/create', $connection->shop_domain, 'wh-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(401);

    expect(Product::withoutTenancy(fn () => Product::query()->count()))->toBe(0);

    $connection->refresh();
    expect($connection->webhook_status)->toBe('failed')
        ->and($connection->status)->toBe('pending');
});

it('creates then updates a ProductChannelListing without duplicating the canonical product', function (): void {
    [, $store, $connection] = shopifyWebhookWorkspace();

    $body = json_encode(shopifyProductPayload());
    $headers = shopifyWebhookHeaders($body, 'test-webhook-secret', 'products/create', $connection->shop_domain, 'wh-product-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1);

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->firstOrFail());
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(1);

    // Update the same remote product — must update in place, not duplicate.
    $updated = shopifyProductPayload();
    $updated['title'] = 'Webhook Product Renamed';
    $body2 = json_encode($updated);
    $headers2 = shopifyWebhookHeaders($body2, 'test-webhook-secret', 'products/update', $connection->shop_domain, 'wh-product-2');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers2, content: $body2)
        ->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1);
    expect($product->fresh()->name)->toBe('Webhook Product Renamed');

    $connection->refresh();
    expect($connection->webhook_status)->toBe('verified')
        ->and($connection->status)->toBe('active');
});

it('creates an Order with platform_connection set to the shopify connection', function (): void {
    [, $store, $connection] = shopifyWebhookWorkspace();

    $body = json_encode(shopifyOrderPayload());
    $headers = shopifyWebhookHeaders($body, 'test-webhook-secret', 'orders/create', $connection->shop_domain, 'wh-order-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->first());

    expect($order)->not->toBeNull()
        ->and($order->platform_connection_id)->toBe($connection->id)
        ->and($order->platform_order_id)->toBe('77001');
});

it('is idempotent for duplicate webhook deliveries', function (): void {
    [, $store, $connection] = shopifyWebhookWorkspace();

    $body = json_encode(shopifyOrderPayload());
    $headers = shopifyWebhookHeaders($body, 'test-webhook-secret', 'orders/create', $connection->shop_domain, 'wh-dup-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->count()))->toBe(1);

    expect(SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wh-dup-1')
        ->where('status', 'ignored_duplicate')
        ->exists()))->toBeTrue();
});

it('cannot let a webhook for one store write into another stores catalog', function (): void {
    [, $storeA, $connectionA] = shopifyWebhookWorkspace('Store A Shopify', 'secret-a');
    [, $storeB] = shopifyWebhookWorkspace('Store B Shopify', 'secret-b');

    $body = json_encode(shopifyProductPayload('9002', 'CROSS-SKU'));
    $headers = shopifyWebhookHeaders($body, 'secret-a', 'products/create', $connectionA->shop_domain, 'wh-cross-1');

    $this->call('POST', "/api/webhooks/shopify/{$connectionA->id}", server: $headers, content: $body)->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeA->id)->count()))->toBe(1)
        ->and(Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeB->id)->count()))->toBe(0);
});

it('rejects a webhook whose shop domain does not match the connection', function (): void {
    [, , $connection] = shopifyWebhookWorkspace();

    $body = json_encode(shopifyProductPayload());
    $headers = shopifyWebhookHeaders($body, 'test-webhook-secret', 'products/create', 'someone-elses-shop.myshopify.com', 'wh-mismatch-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertStatus(403);

    expect(Product::withoutTenancy(fn () => Product::query()->count()))->toBe(0);
});
