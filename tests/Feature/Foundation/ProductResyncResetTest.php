<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\ProductSyncService;

/** @return array{0: User, 1: Store} */
function pclRWorkspace(string $name = 'Cleanup Resync Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pclRWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('removes the channel mapping and clears sync metadata for the selected connection', function (): void {
    [$owner, $store] = pclRWorkspace();
    $woo = pclRWoo($store, 'resync1-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Resync Product', 'sku' => 'RESYNC-1', 'type' => 'simple', 'status' => 'active',
        'price' => 20, 'platform' => 'woocommerce', 'external_id' => 'woo-resync-1', 'synced_at' => now(),
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-resync-1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/reset-sync', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk()->assertJsonPath('results.0.unlinked', true);

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->toBeNull()
        ->and($product->fresh()->synced_at)->toBeNull()
        ->and($product->fresh()->platform)->toBeNull()
        ->and($product->fresh()->external_id)->toBeNull();
});

it('keeps the local product and inventory after a sync reset', function (): void {
    [$owner, $store] = pclRWorkspace();
    $woo = pclRWoo($store, 'resync2-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Resync Product 2', 'sku' => 'RESYNC-2', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-resync-2', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/reset-sync', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->find($product->id)))->not->toBeNull();
});

it('allows a future sync to recreate the mapping after a reset', function (): void {
    [, $store] = pclRWorkspace();
    $woo = pclRWoo($store, 'resync3-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Resync Product 3', 'sku' => 'RESYNC-3', 'type' => 'simple', 'status' => 'active', 'price' => 20,
        'platform' => 'woocommerce', 'external_id' => 'woo-resync-3',
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-resync-3', 'sync_status' => 'synced',
    ]));

    app(\App\Services\Catalog\ProductCleanupService::class)->resetSyncForConnection(
        collect([$product]),
        $woo,
    );

    // Re-importing the same SKU after the reset must attach to the SAME
    // local product (via SKU fallback) with a fresh listing — never create
    // a stray duplicate for the store.
    app(ProductSyncService::class)->saveProduct([
        'external_id' => 'woo-resync-3-new', 'name' => 'Resync Product 3', 'sku' => 'RESYNC-3', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ], $store, 'woocommerce', $woo);

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'RESYNC-3')->count()))->toBe(1);

    $newListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $woo->id)->first());

    expect($newListing)->not->toBeNull()
        ->and($newListing->external_product_id)->toBe('woo-resync-3-new');
});

it('leaves other platform mappings untouched when resetting one connection', function (): void {
    [$owner, $store] = pclRWorkspace();
    $woo = pclRWoo($store, 'resync4-woo.example.com');
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => 'resync4-shop.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Resync Product 4', 'sku' => 'RESYNC-4', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-resync-4', 'sync_status' => 'synced',
    ]));
    $shopifyListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'shop-resync-4', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/reset-sync', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($shopifyListing->id)))->not->toBeNull();
});
