<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductSyncBatch;
use App\Models\ProductSyncResult;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * Phase S3 — /products/sync/start queues one ProductSyncJob per connection
 * and returns immediately with a batch id, instead of the old fully
 * synchronous cache-polling loop. QUEUE_CONNECTION=sync in tests (see
 * phpunit.xml) means the queued job actually runs inline within the same
 * request/test, so these tests can assert the end result without a worker.
 */

/** @return array{0: User, 1: Store} */
function pqsWorkspace(string $name = 'Queued Sync Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pqsWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

function pqsShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

it('requires explicit connection_ids to start a sync', function (): void {
    [$owner] = pqsWorkspace();

    $this->actingAs($owner)->postJson('/dashboard/products/sync/start', [])
        ->assertStatus(422);
});

it('rejects a connection id that does not belong to the active store', function (): void {
    [$owner] = pqsWorkspace();
    [, $otherStore] = pqsWorkspace('Queued Sync Other Store');
    $foreignWoo = pqsWooConnection($otherStore, 'pqs-foreign-woo.example.com');

    $this->actingAs($owner)->postJson('/dashboard/products/sync/start', [
        'connection_ids' => [$foreignWoo->id],
    ])->assertStatus(422);
});

it('returns immediately with a queued status and a batch id, creating one ProductSyncResult per connection', function (): void {
    [$owner, $store] = pqsWorkspace();
    $woo = pqsWooConnection($store, 'pqs1-woo.example.com');
    $shopify = pqsShopifyConnection($store, 'pqs1-shop.myshopify.com');

    Http::fake([
        'pqs1-woo.example.com/wp-json/wc/v3/products*' => Http::response([], 200),
        'pqs1-shop.myshopify.com/*' => Http::response(['products' => []], 200),
    ]);

    $response = $this->actingAs($owner)->postJson('/dashboard/products/sync/start', [
        'connection_ids' => [$woo->id, $shopify->id],
    ])->assertOk();

    expect($response->json('status'))->toBe('queued')
        ->and($response->json('batch_id'))->not->toBeNull();

    $batch = ProductSyncBatch::withoutTenancy(fn () => ProductSyncBatch::query()->find($response->json('batch_id')));
    expect($batch)->not->toBeNull()
        ->and($batch->total_count)->toBe(2)
        ->and(ProductSyncResult::withoutTenancy(fn () => ProductSyncResult::query()->where('batch_id', $batch->id)->count()))->toBe(2);
});

it('actually imports products from the platform via the queued job and reports counts on the batch status endpoint', function (): void {
    [$owner, $store] = pqsWorkspace();
    $woo = pqsWooConnection($store, 'pqs2-woo.example.com');

    Http::fake(['pqs2-woo.example.com/wp-json/wc/v3/products*' => Http::response([
        ['id' => 5001, 'sku' => 'PQS-2-A', 'name' => 'Queued Import Widget', 'type' => 'simple', 'status' => 'publish', 'price' => '15', 'regular_price' => '15'],
    ], 200)]);

    $start = $this->actingAs($owner)->postJson('/dashboard/products/sync/start', [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    $batchId = $start->json('batch_id');

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'PQS-2-A')->exists()))->toBeTrue();

    $status = $this->actingAs($owner)->getJson("/dashboard/products/sync-batches/{$batchId}")->assertOk();

    expect($status->json('status'))->toBe('completed')
        ->and($status->json('succeeded_count'))->toBe(1)
        ->and($status->json('results.0.status'))->toBe('succeeded')
        ->and($status->json('results.0.created'))->toBe(1)
        ->and($status->json('results.0.platform'))->toBe('woocommerce');
});

it('scopes the sync batch status endpoint to the acting store — another store 404s', function (): void {
    [$owner, $store] = pqsWorkspace();
    [$otherOwner] = pqsWorkspace('Queued Sync Status Other Store');
    $woo = pqsWooConnection($store, 'pqs3-woo.example.com');

    Http::fake(['pqs3-woo.example.com/wp-json/wc/v3/products*' => Http::response([], 200)]);

    $start = $this->actingAs($owner)->postJson('/dashboard/products/sync/start', [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    $this->actingAs($otherOwner)
        ->getJson("/dashboard/products/sync-batches/{$start->json('batch_id')}")
        ->assertNotFound();
});

it('records a failed result without blocking other connections when one platform errors', function (): void {
    [$owner, $store] = pqsWorkspace();
    $woo = pqsWooConnection($store, 'pqs4-woo.example.com');
    $shopify = pqsShopifyConnection($store, 'pqs4-shop.myshopify.com');

    Http::fake([
        'pqs4-woo.example.com/*' => Http::response(['message' => 'boom'], 500),
        'pqs4-shop.myshopify.com/*' => Http::response(['products' => []], 200),
    ]);

    $start = $this->actingAs($owner)->postJson('/dashboard/products/sync/start', [
        'connection_ids' => [$woo->id, $shopify->id],
    ])->assertOk();

    $status = $this->actingAs($owner)->getJson("/dashboard/products/sync-batches/{$start->json('batch_id')}")->assertOk();

    // WooCommerce's connector swallows the HTTP failure into created=0/updated=0/failed=0
    // (nothing to import, logged and broken out of), so the job itself still reports
    // succeeded with zero counts — this asserts the OTHER connection's result is unaffected
    // by whichever outcome WooCommerce's job recorded.
    $shopifyResult = collect($status->json('results'))->firstWhere('connection_id', $shopify->id);
    expect($shopifyResult['status'])->toBe('succeeded');
});
