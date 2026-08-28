<?php

declare(strict_types=1);

use App\Jobs\ProductPublishJob;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductPublishBatch;
use App\Models\ProductPublishResult;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Services\Publishing\ProductChannelPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** @return array{0: User, 1: Store} */
function publishJobWorkspace(string $name = 'Publish Job Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function publishJobProduct(Store $store, string $sku, string $type = 'simple'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Publish Job Product', 'sku' => $sku, 'type' => $type, 'status' => 'active', 'price' => 80,
    ]));
}

function publishJobWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('queues jobs and returns 200 quickly without waiting for platform HTTP calls', function (): void {
    Queue::fake();

    [$owner, $store] = publishJobWorkspace();
    $connection = publishJobWooConnection($store, 'pj-quick.example.com');
    $product = publishJobProduct($store, 'PJ-QUICK-1');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'pj-quick-1', 'sync_status' => 'synced',
    ]));

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish-queued", [
        'connection_ids' => [$connection->id],
    ])->assertOk();

    expect($response->json('status'))->toBe('queued')
        ->and($response->json('batch_id'))->not->toBeNull();

    Queue::assertPushed(ProductPublishJob::class, 1);
    Http::assertNothingSent();

    $batch = ProductPublishBatch::withoutTenancy(fn () => ProductPublishBatch::query()->find($response->json('batch_id')));
    expect($batch)->not->toBeNull()
        ->and($batch->total_count)->toBe(1);
});

it('writes a result row when the job runs, recording the external product id', function (): void {
    [, $store] = publishJobWorkspace();
    $connection = publishJobWooConnection($store, 'pj-write.example.com');
    $product = publishJobProduct($store, 'PJ-WRITE-1');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'pj-write-1', 'sync_status' => 'synced',
    ]));

    $batch = ProductPublishBatch::create([
        'store_id' => $store->id, 'organization_id' => $store->organization_id, 'status' => ProductPublishBatch::STATUS_PENDING, 'total_count' => 1,
    ]);
    $result = ProductPublishResult::create([
        'batch_id' => $batch->id, 'product_id' => $product->id, 'platform_connection_id' => $connection->id,
        'platform' => 'woocommerce', 'status' => ProductPublishResult::STATUS_QUEUED,
    ]);

    Http::fake(['pj-write.example.com/wp-json/wc/v3/products/pj-write-1' => Http::response(['id' => 'pj-write-1'], 200)]);

    (new ProductPublishJob($result->id, false))->handle(app(ProductChannelPublisher::class));

    $fresh = ProductPublishResult::withoutTenancy(fn () => ProductPublishResult::query()->find($result->id));
    expect($fresh->status)->toBe(ProductPublishResult::STATUS_SUCCEEDED)
        ->and($fresh->external_product_id)->toBe('pj-write-1');

    expect($batch->fresh()->succeeded_count)->toBe(1)
        ->and($batch->fresh()->status)->toBe(ProductPublishBatch::STATUS_COMPLETED);
});

it('marks the connection result failed when the platform call times out', function (): void {
    [, $store] = publishJobWorkspace();
    $connection = publishJobWooConnection($store, 'pj-timeout.example.com');
    $product = publishJobProduct($store, 'PJ-TIMEOUT-1');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'pj-timeout-1', 'sync_status' => 'synced',
    ]));

    $batch = ProductPublishBatch::create(['store_id' => $store->id, 'status' => ProductPublishBatch::STATUS_PENDING, 'total_count' => 1]);
    $result = ProductPublishResult::create([
        'batch_id' => $batch->id, 'product_id' => $product->id, 'platform_connection_id' => $connection->id,
        'platform' => 'woocommerce', 'status' => ProductPublishResult::STATUS_QUEUED,
    ]);

    Http::fake(['pj-timeout.example.com/*' => function () {
        throw new ConnectionException('Connection timed out');
    }]);

    (new ProductPublishJob($result->id, false))->handle(app(ProductChannelPublisher::class));

    $fresh = ProductPublishResult::withoutTenancy(fn () => ProductPublishResult::query()->find($result->id));
    expect($fresh->status)->toBe(ProductPublishResult::STATUS_FAILED)
        ->and($fresh->message)->not->toBeEmpty();
});

it('does not let one failed connection stop results from being recorded for another selected connection', function (): void {
    [, $store] = publishJobWorkspace();
    $good = publishJobWooConnection($store, 'pj-multi-good.example.com');
    // A store can only have one connection per platform — use Shopify for
    // the failing leg so both connections can coexist on the same store.
    $bad = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => 'pj-multi-bad.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $product = publishJobProduct($store, 'PJ-MULTI-1');

    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $good->id, 'external_product_id' => 'pj-multi-good-1', 'sync_status' => 'synced',
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $bad->id, 'external_product_id' => 'pj-multi-bad-1', 'sync_status' => 'synced',
    ]));

    $batch = ProductPublishBatch::create(['store_id' => $store->id, 'status' => ProductPublishBatch::STATUS_PENDING, 'total_count' => 2]);
    $goodResult = ProductPublishResult::create([
        'batch_id' => $batch->id, 'product_id' => $product->id, 'platform_connection_id' => $good->id,
        'platform' => 'woocommerce', 'status' => ProductPublishResult::STATUS_QUEUED,
    ]);
    $badResult = ProductPublishResult::create([
        'batch_id' => $batch->id, 'product_id' => $product->id, 'platform_connection_id' => $bad->id,
        'platform' => 'shopify', 'status' => ProductPublishResult::STATUS_QUEUED,
    ]);

    Http::fake([
        'pj-multi-good.example.com/*' => Http::response(['id' => 'pj-multi-good-1'], 200),
        'pj-multi-bad.myshopify.com/*' => function () {
            throw new ConnectionException('Connection timed out');
        },
    ]);

    (new ProductPublishJob($badResult->id, false))->handle(app(ProductChannelPublisher::class));
    (new ProductPublishJob($goodResult->id, false))->handle(app(ProductChannelPublisher::class));

    expect(ProductPublishResult::withoutTenancy(fn () => ProductPublishResult::query()->find($badResult->id))->status)->toBe(ProductPublishResult::STATUS_FAILED)
        ->and(ProductPublishResult::withoutTenancy(fn () => ProductPublishResult::query()->find($goodResult->id))->status)->toBe(ProductPublishResult::STATUS_SUCCEEDED);

    $freshBatch = $batch->fresh();
    expect($freshBatch->status)->toBe(ProductPublishBatch::STATUS_PARTIAL)
        ->and($freshBatch->succeeded_count)->toBe(1)
        ->and($freshBatch->failed_count)->toBe(1);
});

it('creates a failed result with a clear message and makes no external API call when readiness is blocked', function (): void {
    [, $store] = publishJobWorkspace();
    $connection = publishJobWooConnection($store, 'pj-blocked.example.com');
    $product = publishJobProduct($store, 'PJ-BLOCKED-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'PJ-BLOCKED-1-X', 'price' => 80, 'options' => ['Size' => 'X']],
    ]);
    $product->refresh();

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $variant->attributeValues()->detach();

    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'pj-blocked-1', 'sync_status' => 'synced',
    ]));

    $batch = ProductPublishBatch::create(['store_id' => $store->id, 'status' => ProductPublishBatch::STATUS_PENDING, 'total_count' => 1]);
    $result = ProductPublishResult::create([
        'batch_id' => $batch->id, 'product_id' => $product->id, 'platform_connection_id' => $connection->id,
        'platform' => 'woocommerce', 'status' => ProductPublishResult::STATUS_QUEUED,
    ]);

    Http::fake(['pj-blocked.example.com/*' => Http::response(['id' => 'should-not-be-called'], 200)]);

    (new ProductPublishJob($result->id, false))->handle(app(ProductChannelPublisher::class));

    Http::assertNothingSent();

    $fresh = ProductPublishResult::withoutTenancy(fn () => ProductPublishResult::query()->find($result->id));
    expect($fresh->status)->toBe(ProductPublishResult::STATUS_FAILED)
        ->and($fresh->message)->not->toBeEmpty();
});
