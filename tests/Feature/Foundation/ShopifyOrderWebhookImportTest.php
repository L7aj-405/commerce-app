<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Shopify order webhooks — dedicated coverage for: orders/cancelled being
| accepted (never a hard reject) but not mutating the order, and variant
| line-item mapping through the same webhook path as regular sync.
| Base creation/idempotency/HMAC/domain-mismatch scenarios already live in
| ShopifyWebhookTest.php — not duplicated here.
|--------------------------------------------------------------------------
*/

function sowiWorkspace(string $name = 'Shopify Order Webhook Store', string $secret = 'sowi-secret'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'webhook_secret' => $secret, 'status' => 'pending', 'webhook_status' => 'pending',
    ]));

    return [$owner, $store, $connection];
}

function sowiHeaders(string $body, string $secret, string $topic, string $shopDomain, string $webhookId): array
{
    return [
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        'CONTENT_TYPE' => 'application/json',
    ];
}

it('accepts orders/cancelled without erroring, without creating or mutating any local order', function (): void {
    [, $store, $connection] = sowiWorkspace();

    $body = json_encode([
        'id' => '90001', 'order_number' => 2001, 'financial_status' => 'voided', 'current_total_price' => '0.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Sara', 'last_name' => 'K', 'email' => 's@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $headers = sowiHeaders($body, 'sowi-secret', 'orders/cancelled', $connection->shop_domain, 'wh-cancel-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)
        ->assertOk()
        ->assertJson(['status' => 'ignored_cancel_topic']);

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->count()))->toBe(0);

    // Still counted as a legitimate, verified webhook — not a rejection.
    expect(SyncLog::withoutTenancy(fn () => SyncLog::query()
        ->where('platform_connection_id', $connection->id)
        ->where('external_id', 'wh-cancel-1')
        ->where('status', 'ignored')
        ->exists()))->toBeTrue();
});

it('never cancels or mutates an order that already exists when orders/cancelled arrives for it', function (): void {
    [$owner, $store, $connection] = sowiWorkspace();

    $createBody = json_encode([
        'id' => '90002', 'order_number' => 2002, 'financial_status' => 'paid', 'current_total_price' => '40.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Sara', 'last_name' => 'K', 'email' => 's@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}",
        server: sowiHeaders($createBody, 'sowi-secret', 'orders/create', $connection->shop_domain, 'wh-c-1'),
        content: $createBody)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->firstOrFail());
    expect($order->platform_order_id)->toBe('90002');

    $cancelBody = json_encode([
        'id' => '90002', 'order_number' => 2002, 'financial_status' => 'voided', 'current_total_price' => '40.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Sara', 'last_name' => 'K', 'email' => 's@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);
    $this->call('POST', "/api/webhooks/shopify/{$connection->id}",
        server: sowiHeaders($cancelBody, 'sowi-secret', 'orders/cancelled', $connection->shop_domain, 'wh-c-2'),
        content: $cancelBody)->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and($order->fresh()->id)->toBe($order->id);
});

it('maps a variant line item through the webhook path, same as a regular sync', function (): void {
    [, $store, $connection] = sowiWorkspace();

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Shirt', 'type' => 'variable', 'status' => 'active', 'price' => 100,
    ]));
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Red / M', 'sku' => 'SHIRT-RED-M', 'price' => 100,
    ]));
    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id,
        'external_product_id' => (string) $product->id, 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => '5501', 'sync_status' => 'synced',
    ]));

    $body = json_encode([
        'id' => '90003', 'order_number' => 2003, 'financial_status' => 'paid', 'current_total_price' => '100.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Nora', 'last_name' => 'B', 'email' => 'n@example.com'],
        'line_items' => [
            ['id' => 1, 'product_id' => (string) $product->id, 'variant_id' => '5501', 'name' => 'Shirt - Red / M', 'sku' => 'SHIRT-RED-M', 'quantity' => 1, 'price' => '100.00'],
        ],
        'created_at' => now()->toIso8601String(),
    ]);
    $headers = sowiHeaders($body, 'sowi-secret', 'orders/create', $connection->shop_domain, 'wh-variant-1');

    $this->call('POST', "/api/webhooks/shopify/{$connection->id}", server: $headers, content: $body)->assertOk();

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->firstOrFail());
    $line = App\Support\OrderLineItems::for($order)[0];

    expect($line['variant_id'])->toBe($variant->id)
        ->and($line['product_id'])->toBe($product->id)
        ->and($line['unmapped'])->toBeFalse();
});
