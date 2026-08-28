<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * Manual sync ("Sync" button on the Shopify connection profile) must keep
 * working exactly as before — this fix only ADDS automatic import, it never
 * removes or weakens the manual path, which remains the tool for first
 * import, backfill, and repair.
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function smswWorkspace(string $name = 'Shopify Manual Sync Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_client_credentials',
        'shop_domain' => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
        'consumer_key' => 'cid', 'consumer_secret' => 'csecret', 'status' => 'active',
    ]));

    return [$owner, $store, $connection];
}

function smswOrdersResponse(string $domain): array
{
    return [
        "{$domain}/admin/oauth/access_token" => Http::response(
            ['access_token' => 'shpca_manual_sync_token', 'scope' => 'read_products,read_orders', 'expires_in' => 86399],
            200,
        ),
        "{$domain}/admin/api/*/orders.json*" => Http::sequence()
            ->push(['orders' => [[
                'id' => '70001', 'order_number' => 9001, 'financial_status' => 'paid', 'current_total_price' => '75.00', 'currency' => 'USD',
                'customer' => ['first_name' => 'Manual', 'last_name' => 'Sync', 'email' => 'manual@example.com'],
                'line_items' => [], 'created_at' => now()->toIso8601String(),
            ]]])
            ->push(['orders' => []]),
    ];
}

it('still imports orders when the user clicks "Sync" in the connection profile', function (): void {
    [$owner, $store, $connection] = smswWorkspace();

    Http::fake(smswOrdersResponse($connection->shop_domain));

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$connection->id}/sync-orders")
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    // QUEUE_CONNECTION=sync in tests — the dispatched OrderSyncJob has
    // already run inline by the time the response above returns.
    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '70001')->exists()))->toBeTrue();
});

it('manual full resync still imports orders with no lower bound', function (): void {
    [$owner, $store, $connection] = smswWorkspace('Manual Full Resync Store');

    Http::fake(smswOrdersResponse($connection->shop_domain));

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$connection->id}/sync-orders/queue")
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    expect(Order::withoutTenancy(fn () => Order::query()->where('store_id', $store->id)->where('platform_order_id', '70001')->exists()))->toBeTrue();
});

it('rejects a manual sync request for a connection belonging to another store', function (): void {
    [, , $connectionA] = smswWorkspace('Manual Sync Tenant A');
    [$ownerB] = smswWorkspace('Manual Sync Tenant B');

    // Route-model-bound tenant-scoped models 404, not 403, on cross-store
    // access — the implicit binding's tenant-scoped query simply never
    // finds connectionA under ownerB's active store (same behavior as
    // every other BelongsToTenant model in this app).
    $this->actingAs($ownerB)
        ->postJson("/dashboard/integrations/connections/{$connectionA->id}/sync-orders")
        ->assertNotFound();
});
