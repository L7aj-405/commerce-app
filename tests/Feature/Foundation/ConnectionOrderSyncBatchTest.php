<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\OrderSyncBatch;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| OrderSyncBatch/OrderSyncResult field shape + "Full order resync" behavior
| — mirrors ProductSyncBatch's pattern but with the ticket's own required
| fields: imported/updated/skipped/failed_count, last_error, started_at,
| completed_at.
|--------------------------------------------------------------------------
*/

function cosbWorkspace(string $name = 'Connection Order Sync Batch Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cosbWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('reports store_id/organization_id and the full imported/updated/skipped/failed field set on the batch', function (): void {
    [$owner, $store] = cosbWorkspace();
    $woo = cosbWoo($store, 'cosb1-woo.example.com');

    Http::fake(['cosb1-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([
            ['id' => 1, 'number' => '1', 'status' => 'processing', 'total' => '10', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()],
            ['id' => '', 'number' => '2', 'status' => 'processing', 'total' => '20', 'line_items' => [], 'billing' => []], // empty external id -> skipped
        ], 200)
        ->push([], 200)]);

    $start = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")
        ->assertOk();

    $batch = OrderSyncBatch::withoutTenancy(fn () => OrderSyncBatch::query()->find($start->json('batch_id')));
    expect($batch->store_id)->toBe($store->id)
        ->and($batch->organization_id)->toBe($store->organization_id)
        ->and($batch->imported_count)->toBe(1)
        ->and($batch->skipped_count)->toBe(1)
        ->and($batch->failed_count)->toBe(0)
        ->and($batch->started_at)->not->toBeNull()
        ->and($batch->completed_at)->not->toBeNull();
});

it('a full order resync scans older orders but updates the existing one, never duplicating it', function (): void {
    [$owner, $store] = cosbWorkspace();
    $woo = cosbWoo($store, 'cosb2-woo.example.com');
    $base = "/dashboard/integrations/connections/{$woo->id}";

    Http::fake(['cosb2-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 4001, 'number' => '4001', 'status' => 'processing', 'total' => '80.00', 'currency' => 'MAD', 'line_items' => [], 'billing' => [], 'date_created' => now()->subDays(90)->toIso8601String()]], 200)
        ->push([], 200)
        // A "full resync" pull of the same, older order — total changed.
        ->push([['id' => 4001, 'number' => '4001', 'status' => 'processing', 'total' => '95.00', 'currency' => 'MAD', 'line_items' => [], 'billing' => [], 'date_created' => now()->subDays(90)->toIso8601String()]], 200)
        ->push([], 200)]);

    $this->actingAs($owner)->postJson("{$base}/sync-orders")->assertOk();
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1);

    $resync = $this->actingAs($owner)->postJson("{$base}/sync-orders/queue")->assertOk();
    expect($resync->json('status'))->toBe('queued')
        ->and($resync->json('message'))->toBe('Full order resync queued.');

    $orders = Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->get());
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->platform_order_id)->toBe('4001')
        ->and((float) $orders->first()->total)->toBe(95.0);
});

it('scopes the batch status endpoint to the acting store, never leaking another store\'s batch', function (): void {
    [$ownerA, $storeA] = cosbWorkspace('COSB Store A');
    [$ownerB] = cosbWorkspace('COSB Store B');
    $woo = cosbWoo($storeA, 'cosb3-woo.example.com');

    Http::fake(['cosb3-woo.example.com/wp-json/wc/v3/orders*' => Http::response([], 200)]);

    $start = $this->actingAs($ownerA)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")
        ->assertOk();

    // Store B has no connection with this id at all under its own tenant
    // scope — the whole route 404s before the batch is ever inspected.
    $this->actingAs($ownerB)
        ->getJson("/dashboard/integrations/connections/{$woo->id}/sync-orders/batches/{$start->json('batch_id')}")
        ->assertNotFound();
});

it('does not delete existing orders when resetting the order sync cursor, and this Ozon delivery connection is unaffected', function (): void {
    [$owner, $store] = cosbWorkspace();
    $woo = cosbWoo($store, 'cosb4-woo.example.com');

    // A DeliveryConnection is a totally different model — asserts the order
    // sync batch pipeline never touches unrelated per-store bookkeeping.
    $delivery = DeliveryConnection::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'X', 'api_key' => 'Y'], 'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    Http::fake(['cosb4-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 5001, 'number' => '5001', 'status' => 'processing', 'total' => '30.00', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();
    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-order-cursor")->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1)
        ->and($delivery->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});
