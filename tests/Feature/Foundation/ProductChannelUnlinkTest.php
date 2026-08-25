<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function pclUWorkspace(string $name = 'Cleanup Unlink Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pclUWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

function pclUShopify(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function pclUProduct(Store $store, string $sku, ?string $platform = null, ?string $externalId = null): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => "Unlink Product {$sku}", 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
        'platform' => $platform, 'external_id' => $externalId,
    ]));
}

it('unlinks a product from one connection only, leaving other connections mapped', function (): void {
    [$owner, $store] = pclUWorkspace();
    $woo = pclUWoo($store, 'unlink1-woo.example.com');
    $shopify = pclUShopify($store, 'unlink1-shop.myshopify.com');
    $product = pclUProduct($store, 'UNLINK-1');

    $wooListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-1', 'sync_status' => 'synced',
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'shop-1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/unlink-channel', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk()->assertJsonPath('results.0.unlinked', true);

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($wooListing->id)))->toBeNull()
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
            ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->exists()))->toBeTrue();
});

it('cascades unlink to the variant channel listing for that connection', function (): void {
    [$owner, $store] = pclUWorkspace();
    $woo = pclUWoo($store, 'unlink2-woo.example.com');
    $product = pclUProduct($store, 'UNLINK-2');
    $variant = \App\Models\ProductVariant::withoutTenancy(fn () => \App\Models\ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Only Variant', 'sku' => 'UNLINK-2-V1', 'price' => 20, 'cost' => 0,
    ]));

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-2', 'sync_status' => 'synced',
    ]));
    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $woo->id, 'external_variant_id' => 'woo-2-v1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/unlink-channel', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk();

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->find($variantListing->id)))->toBeNull();
});

it('never deletes the local product, its inventory, or its orders when unlinking', function (): void {
    [$owner, $store] = pclUWorkspace();
    $woo = pclUWoo($store, 'unlink3-woo.example.com');
    $product = pclUProduct($store, 'UNLINK-3');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-3', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/unlink-channel', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk();

    expect($product->fresh())->not->toBeNull()
        ->and($product->fresh()->trashed())->toBeFalse();
});

it('clears legacy platform/external_id fields only for the unlinked connection\'s platform', function (): void {
    [$owner, $store] = pclUWorkspace();
    $woo = pclUWoo($store, 'unlink4-woo.example.com');
    $product = pclUProduct($store, 'UNLINK-4', platform: 'woocommerce', externalId: 'legacy-woo-4');

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/unlink-channel', [
        'product_ids' => [$product->id],
        'platform_connection_id' => $woo->id,
    ])->assertOk();

    expect($product->fresh()->platform)->toBeNull()
        ->and($product->fresh()->external_id)->toBeNull();
});

it('does not unlink a product belonging to another store even if its id is included', function (): void {
    [, $storeA] = pclUWorkspace('Unlink Org A');
    [$ownerB, $storeB] = pclUWorkspace('Unlink Org B');
    $wooB = pclUWoo($storeB, 'unlink5-woo.example.com');
    $productA = pclUProduct($storeA, 'UNLINK-5');
    $listingA = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $productA->id, 'platform_connection_id' => pclUWoo($storeA, 'unlink5-woo-a.example.com')->id,
        'external_product_id' => 'woo-5', 'sync_status' => 'synced',
    ]));

    $response = $this->actingAs($ownerB)->postJson('/dashboard/products/bulk/unlink-channel', [
        'product_ids' => [$productA->id],
        'platform_connection_id' => $wooB->id,
    ])->assertOk();

    expect($response->json('summary.matched'))->toBe(0);
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listingA->id)))->not->toBeNull();
});
