<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderSyncBatch;
use App\Models\OrderSyncResult;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| "Sync orders now" must return immediately with a batch_id — the platform
| API loop runs in App\Jobs\OrderSyncJob, queued, never inline in the HTTP
| request (that inline loop is what could hit PHP's max_execution_time).
| QUEUE_CONNECTION=sync in tests runs the job inline within the same
| request/test — see ProductQueuedSyncTest.php's own note on this.
|--------------------------------------------------------------------------
*/

function osqWorkspace(string $name = 'Order Sync Queue Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function osqWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('returns immediately with a queued status, a batch_id, and creates one OrderSyncResult', function (): void {
    [$owner, $store] = osqWorkspace();
    $woo = osqWooConnection($store, 'osq1-woo.example.com');

    Http::fake(['osq1-woo.example.com/wp-json/wc/v3/orders*' => Http::response([], 200)]);

    $response = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")
        ->assertOk();

    expect($response->json('status'))->toBe('queued')
        ->and($response->json('message'))->toBe('Order sync queued.')
        ->and($response->json('batch_id'))->not->toBeNull();

    $batch = OrderSyncBatch::withoutTenancy(fn () => OrderSyncBatch::query()->find($response->json('batch_id')));
    expect($batch)->not->toBeNull()
        ->and($batch->store_id)->toBe($store->id)
        ->and($batch->total_count)->toBe(1);

    $result = OrderSyncResult::withoutTenancy(fn () => OrderSyncResult::query()->where('batch_id', $batch->id)->first());
    expect($result)->not->toBeNull()
        ->and($result->platform_connection_id)->toBe($woo->id)
        ->and($result->platform)->toBe('woocommerce')
        ->and($result->full_resync)->toBeFalse();
});

it('does not run the platform sync loop inline — the HTTP response never carries sync results', function (): void {
    [$owner, $store] = osqWorkspace();
    $woo = osqWooConnection($store, 'osq2-woo.example.com');

    Http::fake(['osq2-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 1, 'number' => '1', 'status' => 'processing', 'total' => '10', 'line_items' => [], 'billing' => []]], 200)
        ->push([], 200)]); // page 2 — stop the pull loop

    $response = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")
        ->assertOk();

    // The old synchronous endpoint returned {ok, status, summary, error} —
    // the new queued contract returns only {batch_id, status, message}.
    // Neither an imported/updated count nor a "summary" key exists on the
    // immediate response at all — that data only ever lands on the batch.
    expect($response->json())->not->toHaveKey('summary')
        ->and($response->json())->not->toHaveKey('ok')
        ->and($response->json('status'))->toBe('queued');
});

it('imports orders in the background job and reports counts on the batch status endpoint', function (): void {
    [$owner, $store] = osqWorkspace();
    $woo = osqWooConnection($store, 'osq3-woo.example.com');

    Http::fake(['osq3-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 9101, 'number' => '5001', 'status' => 'processing', 'total' => '75.00', 'currency' => 'MAD', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)]); // page 2 — stop the pull loop

    $start = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")
        ->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->where('platform_order_id', '9101')->exists()))->toBeTrue();

    $status = $this->actingAs($owner)
        ->getJson("/dashboard/integrations/connections/{$woo->id}/sync-orders/batches/{$start->json('batch_id')}")
        ->assertOk();

    expect($status->json('status'))->toBe('completed')
        ->and($status->json('imported_count'))->toBe(1)
        ->and($status->json('results.0.status'))->toBe('completed')
        ->and($status->json('results.0.imported'))->toBe(1)
        ->and($status->json('results.0.platform'))->toBe('woocommerce');
});

it('scopes the order sync batch status endpoint to the acting store — another store 404s', function (): void {
    [$owner, $store] = osqWorkspace();
    [$otherOwner] = osqWorkspace('Order Sync Queue Other Store');
    $woo = osqWooConnection($store, 'osq4-woo.example.com');

    Http::fake(['osq4-woo.example.com/wp-json/wc/v3/orders*' => Http::response([], 200)]);

    $start = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")
        ->assertOk();

    // PlatformConnection is tenant-scoped (BelongsToTenant) — implicit route
    // model binding for {connection} can't even find store A's connection
    // under store B's tenant context, so this 404s before requireConnection()
    // ever runs.
    $this->actingAs($otherOwner)
        ->getJson("/dashboard/integrations/connections/{$woo->id}/sync-orders/batches/{$start->json('batch_id')}")
        ->assertNotFound();
});
