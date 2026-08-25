<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\OrganizationMember;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| A genuinely NEW order (via webhook or sync) creates one OrderNotification
| per user who can see orders (orders.view) for that store — never for a
| re-synced/updated existing order, never leaking across organizations.
|--------------------------------------------------------------------------
*/

function nonWorkspace(string $name = 'New Order Notification Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function nonMember(Store $store, string $roleName): User
{
    $role = $store->roles()->where('name', $roleName)->firstOrFail();
    $user = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $user->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    // The V2 workspace model requires an ACTIVE organization membership too
    // — a StoreMember row alone is not enough (User::storeMembershipFor()
    // returns null for a user who isn't also an active org member).
    OrganizationMember::create([
        'organization_id' => $store->organization_id, 'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_MEMBER, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $user;
}

function nonShopifyWebhook(PlatformConnection $connection, string $externalId, string $webhookId): void
{
    $body = json_encode([
        'id' => $externalId, 'order_number' => 4001, 'financial_status' => 'paid', 'current_total_price' => '50.00', 'currency' => 'USD',
        'customer' => ['first_name' => 'Nina', 'last_name' => 'R', 'email' => 'nina@example.com'],
        'line_items' => [], 'created_at' => now()->toIso8601String(),
    ]);

    test()->call('POST', "/api/webhooks/shopify/{$connection->id}", server: [
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $connection->shop_domain,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'non-secret', true)),
        'CONTENT_TYPE' => 'application/json',
    ], content: $body)->assertOk();
}

it('creates a new_order notification for a user who can view orders when a genuinely new order arrives', function (): void {
    [$owner, $store] = nonWorkspace();
    $dispatcher = nonMember($store, 'Confirmation agent');

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'non1-shop.myshopify.com', 'webhook_secret' => 'non-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));

    nonShopifyWebhook($connection, '77001', 'non-wh-1');

    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->firstOrFail());

    foreach ([$owner, $dispatcher] as $user) {
        expect(OrderNotification::withoutTenancy(fn () => OrderNotification::query()
            ->where('user_id', $user->id)
            ->where('order_id', $order->id)
            ->where('type', OrderNotification::TYPE_NEW_ORDER)
            ->exists()))->toBeTrue();
    }
});

it('does not create a second notification when the same order is re-synced (not genuinely new)', function (): void {
    [$owner, $store] = nonWorkspace();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'non2-shop.myshopify.com', 'webhook_secret' => 'non-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));

    nonShopifyWebhook($connection, '77002', 'non-wh-2');
    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->firstOrFail());

    // A duplicate webhook delivery id is ignored before ever reaching the
    // mapper, so no second OrderCreated fires — but assert the count stays
    // at exactly one regardless of the mechanism.
    nonShopifyWebhook($connection, '77002', 'non-wh-2');

    expect(OrderNotification::withoutTenancy(fn () => OrderNotification::query()
        ->where('user_id', $owner->id)->where('order_id', $order->id)->count()))->toBe(1);
});

it('a user without orders.view never gets a new-order notification', function (): void {
    [$owner, $store] = nonWorkspace();
    $driver = nonMember($store, 'Delivery agent'); // orders.deliver only, no orders.view

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'non3-shop.myshopify.com', 'webhook_secret' => 'non-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));

    nonShopifyWebhook($connection, '77003', 'non-wh-3');
    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->firstOrFail());

    expect(OrderNotification::withoutTenancy(fn () => OrderNotification::query()
        ->where('user_id', $driver->id)->where('order_id', $order->id)->exists()))->toBeFalse();
});

it('never leaks a notification across organizations/stores', function (): void {
    [$ownerA, $storeA] = nonWorkspace('NON Store A');
    [$ownerB] = nonWorkspace('NON Store B');

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $storeA->id, 'platform' => 'shopify', 'connection_method' => 'webhook',
        'shop_domain' => 'non4-shop.myshopify.com', 'webhook_secret' => 'non-secret',
        'status' => 'active', 'webhook_status' => 'verified',
    ]));

    nonShopifyWebhook($connection, '77004', 'non-wh-4');
    $order = Order::withoutTenancy(fn () => Order::query()->where('store_id', $storeA->id)->firstOrFail());

    expect(OrderNotification::withoutTenancy(fn () => OrderNotification::query()->where('user_id', $ownerA->id)->where('order_id', $order->id)->exists()))->toBeTrue()
        ->and(OrderNotification::withoutTenancy(fn () => OrderNotification::query()->where('user_id', $ownerB->id)->exists()))->toBeFalse();
});
