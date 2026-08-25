<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrganizationMember;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| GET /dashboard/notifications/order-counts (polling endpoint) and
| POST /dashboard/notifications/mark-seen — badge counts, per-user "seen"
| state, and cross-organization isolation.
|--------------------------------------------------------------------------
*/

function onbWorkspace(string $name = 'Order Notification Badge Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function onbMember(Store $store, string $roleName): User
{
    $role = $store->roles()->where('name', $roleName)->firstOrFail();
    $user = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $user->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);
    OrganizationMember::create([
        'organization_id' => $store->organization_id, 'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_MEMBER, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $user;
}

function onbShopifyWebhook(PlatformConnection $connection, string $externalId, string $webhookId): void
{
    $body = json_encode([
        'id' => $externalId, 'order_number' => 6001, 'financial_status' => 'paid', 'current_total_price' => '20.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Omar', 'last_name' => 'T', 'email' => 'omar@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);

    test()->call('POST', "/api/webhooks/shopify/{$connection->id}", server: [
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $connection->shop_domain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'onb-secret', true)),
        'CONTENT_TYPE' => 'application/json',
    ], content: $body)->assertOk();
}

it('the badge count increases for a user with access when a new order arrives', function (): void {
    [$owner, $store] = onbWorkspace();

    $baseline = $this->actingAs($owner)->getJson('/dashboard/notifications/order-counts')->assertOk();
    expect($baseline->json('new_orders_count'))->toBe(0);

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'onb1-shop.myshopify.com', 'webhook_secret' => 'onb-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));
    onbShopifyWebhook($connection, '88001', 'onb-wh-1');

    $after = $this->actingAs($owner)->getJson('/dashboard/notifications/order-counts')->assertOk();
    expect($after->json('new_orders_count'))->toBe(1)
        ->and($after->json('latest_notifications'))->toHaveCount(1)
        ->and($after->json('latest_notifications.0.title'))->toBe('New order received from Shopify');
});

it('opening the Confirmation Desk marks unseen new-order notifications as seen for that user only', function (): void {
    [$owner, $store] = onbWorkspace();
    $agent = onbMember($store, 'Confirmation agent');

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'onb2-shop.myshopify.com', 'webhook_secret' => 'onb-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));
    onbShopifyWebhook($connection, '88002', 'onb-wh-2');

    expect($this->actingAs($agent)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(1);

    $this->actingAs($agent)->postJson('/dashboard/notifications/mark-seen', ['context' => 'confirmation_desk'])
        ->assertOk()
        ->assertJson(['ok' => true, 'marked_count' => 1]);

    expect($this->actingAs($agent)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(0)
        // The owner never opened anything — their own unseen count is untouched.
        ->and($this->actingAs($owner)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(1);
});

it('marking one order detail as seen only clears that order\'s notification, not others', function (): void {
    [$owner, $store] = onbWorkspace();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'onb3-shop.myshopify.com', 'webhook_secret' => 'onb-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));
    onbShopifyWebhook($connection, '88003', 'onb-wh-3a');
    onbShopifyWebhook($connection, '88004', 'onb-wh-3b');

    expect($this->actingAs($owner)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(2);

    $seenOrder = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '88003')->firstOrFail());

    $this->actingAs($owner)->postJson('/dashboard/notifications/mark-seen', [
        'context' => 'order_detail', 'order_id' => $seenOrder->id,
    ])->assertOk()->assertJson(['ok' => true, 'marked_count' => 1]);

    expect($this->actingAs($owner)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(1);
});

it('confirmation_pending_count is only shown to a user who holds orders.confirm', function (): void {
    [$owner, $store] = onbWorkspace();
    $viewer = onbMember($store, 'Viewer'); // orders.view but no orders.confirm

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'onb4-shop.myshopify.com', 'webhook_secret' => 'onb-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));
    onbShopifyWebhook($connection, '88005', 'onb-wh-4');

    expect($this->actingAs($owner)->getJson('/dashboard/notifications/order-counts')->json('confirmation_pending_count'))->toBe(1)
        ->and($this->actingAs($viewer)->getJson('/dashboard/notifications/order-counts')->json('confirmation_pending_count'))->toBe(0);
});

it('never leaks another organization\'s badge counts', function (): void {
    [$ownerA, $storeA] = onbWorkspace('ONB Store A');
    [$ownerB] = onbWorkspace('ONB Store B');

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $storeA->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'onb5-shop.myshopify.com', 'webhook_secret' => 'onb-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));
    onbShopifyWebhook($connection, '88006', 'onb-wh-5');

    expect($this->actingAs($ownerA)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(1)
        ->and($this->actingAs($ownerB)->getJson('/dashboard/notifications/order-counts')->json('new_orders_count'))->toBe(0);
});
