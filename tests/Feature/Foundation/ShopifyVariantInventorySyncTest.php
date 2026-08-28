<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\ProductPushService;
use Illuminate\Support\Facades\Http;

/**
 * A Shopify variant's stock lives on InventoryLevel (inventory_item_id +
 * location_id), never a product/variant update payload. These exercise the
 * variant-specific resolution chain (saved id -> Shopify fetch -> persist)
 * and the tracking-activation retry, independent of the HTTP stock
 * adjustment endpoint (see ShopifyStockAdjustmentPushTest for that).
 */

/** @return array{0: User, 1: Store} */
function svisWorkspace(string $name = 'Shopify Variant Inventory Sync Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function svisShopifyConnection(Store $store, string $domain, array $metadata = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test', 'metadata' => $metadata,
    ]));
}

/** @return array{0: Product, 1: ProductVariant, 2: ProductChannelListing} */
function svisLinkedVariant(Store $store, PlatformConnection $shopify, string $sku, string $externalProductId, string $externalVariantId, ?string $inventoryItemId): array
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Inventory Widget', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 40, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => $externalProductId, 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => $externalVariantId,
        'external_inventory_item_id' => $inventoryItemId, 'sync_status' => 'synced',
    ]));

    return [$product, $variant->fresh(), $listing];
}

it('pushes variant stock using the saved external_inventory_item_id (test 7)', function (): void {
    [, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis1-shop.myshopify.com', ['location_id' => '900001']);
    [, $variant] = svisLinkedVariant($store, $shopify, 'SVIS-1', 'svis-1-remote', '30001', '40001');

    Http::fake(['svis1-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 9]], 200)]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSentCount(1); // no /variants/{id}.json fetch — the saved id was used directly.
    // No local Stock/balance was recorded for this variant, so the pushed
    // quantity is legitimately 0 — this test only cares that the saved
    // inventory_item_id/location_id were used, not the quantity value.
    Http::assertSent(fn ($r) => $r['inventory_item_id'] === '40001' && $r['location_id'] === '900001');
});

it('resolves a missing external_inventory_item_id from Shopify and saves it (test 8)', function (): void {
    [, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis2-shop.myshopify.com', ['location_id' => '900002']);
    [, $variant] = svisLinkedVariant($store, $shopify, 'SVIS-2', 'svis-2-remote', '30002', null);

    Http::fake([
        'svis2-shop.myshopify.com/admin/api/*/variants/30002.json' => Http::response(['variant' => ['id' => 30002, 'inventory_item_id' => 40002]], 200),
        'svis2-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 4]], 200),
    ]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['inventory_item_id'] === '40002');

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $variant->id)->value('external_inventory_item_id')))->toBe('40002');
});

it('resolves the Shopify location id and stores it in PlatformConnection metadata (test 9)', function (): void {
    [, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis3-shop.myshopify.com'); // no location metadata yet
    [, $variant] = svisLinkedVariant($store, $shopify, 'SVIS-3', 'svis-3-remote', '30003', '40003');

    Http::fake([
        'svis3-shop.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => [['id' => 900003, 'name' => 'Main', 'active' => true]]], 200),
        'svis3-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 2]], 200),
    ]);

    app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect(PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($shopify->id))->metadata['location_id'])->toBe('900003');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['location_id'] === '900003');
});

it('does not roll back local inventory linkage when the Shopify variant push fails (test 11)', function (): void {
    [, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis11-shop.myshopify.com', ['location_id' => '900011']);
    [, $variant] = svisLinkedVariant($store, $shopify, 'SVIS-11', 'svis-11-remote', '30011', '40011');

    Http::fake(['svis11-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'Server error'], 500)]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeFalse();
    // The variant's channel mapping (and the inventory_item_id it already
    // had) is untouched by a failed push — nothing to roll back.
    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $variant->id)->value('external_inventory_item_id')))->toBe('40011');
});

it('never sends inventory_quantity in the variable-product publish payload (test 12)', function (): void {
    [$owner, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis12-shop.myshopify.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'No Quantity Variant Widget', 'sku' => 'SVIS-12', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'SVIS-12-S', 'price' => 40, 'options' => ['Size' => 'S']],
    ]);

    Http::fake(['svis12-shop.myshopify.com/admin/api/*/products.json' => Http::response([
        'product' => ['id' => 'svis-12-remote', 'variants' => [['id' => 50012, 'option1' => 'S', 'sku' => 'SVIS-12-S', 'inventory_item_id' => 60012]]],
    ], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    Http::assertSent(function ($r) {
        foreach ($r['product']['variants'] ?? [] as $v) {
            if (array_key_exists('inventory_quantity', $v) || array_key_exists('old_inventory_quantity', $v)) {
                return false;
            }
        }

        return true;
    });
});

it('activates inventory tracking and retries once when Shopify reports the item is not stocked at the location', function (): void {
    [, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis13-shop.myshopify.com', ['location_id' => '900013']);
    [, $variant] = svisLinkedVariant($store, $shopify, 'SVIS-13', 'svis-13-remote', '30013', '40013');

    Http::fake([
        'svis13-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::sequence()
            ->push(['errors' => 'Inventory item is not stocked at this location.'], 422)
            ->push(['inventory_level' => ['available' => 5]], 200),
        'svis13-shop.myshopify.com/admin/api/*/inventory_items/40013.json' => Http::response(['inventory_item' => ['id' => 40013, 'tracked' => true]], 200),
        'svis13-shop.myshopify.com/admin/api/*/inventory_levels/connect.json' => Http::response(['inventory_level' => ['available' => 0]], 201),
    ]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeTrue();
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/inventory_items/40013.json'));
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/inventory_levels/connect.json'));
    Http::assertSentCount(4); // failed set, activate, connect, retried set
});

it('does not log the Shopify access token when a variant inventory push fails', function (): void {
    [, $store] = svisWorkspace();
    $shopify = svisShopifyConnection($store, 'svis14-shop.myshopify.com', ['location_id' => '900014']);
    [, $variant] = svisLinkedVariant($store, $shopify, 'SVIS-14', 'svis-14-remote', '30014', '40014');

    Http::fake(['svis14-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'Server error'], 500)]);

    $results = app(ProductPushService::class)->pushVariantStock($variant, 'shopify');

    expect($results[0]['success'] ?? null)->toBeFalse()
        ->and($results[0]['message'] ?? '')->not->toContain('shpat_test');
});
