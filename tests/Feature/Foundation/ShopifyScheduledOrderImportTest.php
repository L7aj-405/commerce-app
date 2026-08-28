<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Http;

/**
 * routes/console.php's every-minute Schedule::call() is already
 * platform-agnostic: it iterates every PlatformConnection with
 * is_syncing=false and calls OrderSyncService::syncFromPlatform($store,
 * $connection, $since) — the EXACT method exercised here — regardless of
 * platform or connection_method. This proves that call genuinely imports
 * new Shopify orders with no UI interaction, and never runs an unbounded
 * historical pull (a $since cursor is always passed once one exists).
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function ssoiWorkspace(string $name = 'Shopify Scheduled Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'consumer_key' => 'cid', 'consumer_secret' => 'csecret', 'status' => 'active',
        'is_syncing' => false, 'last_synced_at' => now()->subMinutes(10),
    ]));

    return [$owner, $store, $connection];
}

it('imports a new Shopify order via the exact call the scheduler makes, with no UI interaction', function (): void {
    [, $store, $connection] = ssoiWorkspace();

    Http::fake([
        "{$connection->shop_domain}/admin/oauth/access_token" => Http::response(
            ['access_token' => 'shpca_scheduled_token', 'scope' => 'read_products,read_orders', 'expires_in' => 86399],
            200,
        ),
        "{$connection->shop_domain}/admin/api/*/orders.json*" => Http::sequence()
            ->push(['orders' => [[
                'id' => '80101', 'order_number' => 9101, 'financial_status' => 'paid', 'current_total_price' => '18.50', 'currency' => 'USD',
                'customer' => ['first_name' => 'Auto', 'last_name' => 'Poll', 'email' => 'autopoll@example.com'],
                'line_items' => [], 'created_at' => now()->toIso8601String(),
            ]]])
            ->push(['orders' => []]),
    ]);

    // Mirrors routes/console.php's Schedule::call() body exactly: since =
    // last_synced_at minus a small overlap window.
    $since = $connection->last_synced_at->subMinutes(5);
    app(OrderSyncService::class)->syncFromPlatform($store, $connection, $since);

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '80101')->exists()))->toBeTrue();

    $connection->refresh();
    expect($connection->is_syncing)->toBeFalse()
        ->and($connection->last_synced_at)->not->toBeNull();
});

it('never runs an unbounded historical pull — the since cursor is always passed once one exists', function (): void {
    [, $store, $connection] = ssoiWorkspace('Bounded Scheduled Store');

    $capturedParams = null;

    Http::fake([
        "{$connection->shop_domain}/admin/oauth/access_token" => Http::response(
            ['access_token' => 'shpca_bounded_token', 'scope' => 'read_products,read_orders', 'expires_in' => 86399],
            200,
        ),
        "{$connection->shop_domain}/admin/api/*/orders.json*" => function ($request) use (&$capturedParams) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $capturedParams);

            return Http::response(['orders' => []], 200);
        },
    ]);

    $since = $connection->last_synced_at->subMinutes(5);
    app(OrderSyncService::class)->syncFromPlatform($store, $connection, $since);

    expect($capturedParams)->toHaveKey('created_at_min')
        ->and($capturedParams['created_at_min'])->toBe($since->toIso8601String());
});

it('leaves is_syncing reset to false even when the platform call fails, so the next scheduled tick is never blocked', function (): void {
    [, $store, $connection] = ssoiWorkspace('Scheduled Failure Store');

    Http::fake([
        "{$connection->shop_domain}/admin/oauth/access_token" => Http::response([], 500),
    ]);

    app(OrderSyncService::class)->syncFromPlatform($store, $connection, $connection->last_synced_at);

    $connection->refresh();
    expect($connection->is_syncing)->toBeFalse()
        ->and($connection->last_sync_error)->not->toBeNull();
});
