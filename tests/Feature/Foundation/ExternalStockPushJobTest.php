<?php

declare(strict_types=1);

use App\Jobs\ExternalStockPushJob;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * Phase S6 — ExternalStockPushJob is the optional async wrapper around
 * ProductPushService::pushStock()/pushVariantStock(), for a future caller
 * that wants to fire-and-forget an external stock push instead of the
 * existing synchronous `/products/{product}/stock` flow (which stays
 * synchronous by default — see ProductController::adjustStock(), unchanged).
 * Local inventory is always committed via InventoryEngine BEFORE this job
 * runs; the job itself never touches local inventory and never rolls it
 * back on an external failure.
 */

/** @return array{0: User, 1: Store} */
function espjWorkspace(string $name = 'External Stock Push Job Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function espjShopifyConnection(Store $store, string $domain, array $metadata = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test', 'metadata' => $metadata,
    ]));
}

it('pushes the committed local quantity to Shopify for a simple product', function (): void {
    [, $store] = espjWorkspace();
    $shopify = espjShopifyConnection($store, 'espj1-shop.myshopify.com', ['location_id' => '900001']);
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Push Job Widget', 'sku' => 'ESPJ-1', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'espj-1-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '30001'],
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 17, 'adjustment', null, null, 'Initial count', false);

    Http::fake(['espj1-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 17]], 200)]);

    $job = new ExternalStockPushJob($product->id, null, 'shopify');
    $results = app()->call([$job, 'handle']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['success'])->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['available'] === 17 && $r['inventory_item_id'] === '30001');
});

it('pushes the committed local quantity to Shopify for a specific variant', function (): void {
    [, $store] = espjWorkspace();
    $shopify = espjShopifyConnection($store, 'espj2-shop.myshopify.com', ['location_id' => '900002']);
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Push Job Variable', 'sku' => 'ESPJ-2', 'type' => 'variable', 'status' => 'active', 'price' => 40,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'ESPJ-2-S', 'price' => 40, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'espj-2-remote', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => 'espj-2-var-remote', 'external_inventory_item_id' => '30002', 'sync_status' => 'synced',
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product, $variant);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 9, 'adjustment', null, null, 'Initial count', false);

    Http::fake(['espj2-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 9]], 200)]);

    $job = new ExternalStockPushJob($product->id, $variant->id, 'shopify');
    $results = app()->call([$job, 'handle']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['success'])->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['available'] === 9 && $r['inventory_item_id'] === '30002');
});

it('never rolls back local inventory when the external push fails, and reports failure in the result', function (): void {
    [, $store] = espjWorkspace();
    $shopify = espjShopifyConnection($store, 'espj3-shop.myshopify.com', ['location_id' => '900003']);
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Push Job Fails', 'sku' => 'ESPJ-3', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'espj-3-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '30003'],
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 12, 'adjustment', null, null, 'Initial count', false);

    Http::fake(['espj3-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'boom'], 500)]);

    $job = new ExternalStockPushJob($product->id, null, 'shopify');
    $results = app()->call([$job, 'handle']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['success'])->toBeFalse();

    $balance = \App\Models\WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => \App\Models\WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());
    expect($balance->on_hand)->toBe(12);
});

it('returns an empty result set when the product has no listing for the requested platform', function (): void {
    [, $store] = espjWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'No Listing', 'sku' => 'ESPJ-4', 'type' => 'simple', 'status' => 'active', 'price' => 30,
    ]));

    $job = new ExternalStockPushJob($product->id, null, 'shopify');
    $results = app()->call([$job, 'handle']);

    expect($results)->toBe([]);
    Http::assertNothingSent();
});

it('returns an empty result set when the product no longer exists', function (): void {
    $job = new ExternalStockPushJob('01HNOTREALPRODUCTID000000', null, 'shopify');
    $results = app()->call([$job, 'handle']);

    expect($results)->toBe([]);
});
