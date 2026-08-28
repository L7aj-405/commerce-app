<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Models\WarehouseInventoryBalance;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * POST /dashboard/products/{product}/stock is the inventory-safe adjustment
 * flow: local inventory (InventoryEngine) always commits first, then a
 * Shopify InventoryLevel push is attempted. A failed Shopify push must
 * never roll back the already-committed local adjustment, and the flash
 * message must clearly report which platform(s) synced or failed.
 */

/** @return array{0: User, 1: Store} */
function saspWorkspace(string $name = 'Stock Adjustment Push Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function saspShopifyConnection(Store $store, string $domain, array $metadata = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test', 'metadata' => $metadata,
    ]));
}

function saspSimpleProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Push Widget', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 35,
    ]));
}

it('updates local inventory then pushes the new quantity to Shopify via InventoryLevel (test 1)', function (): void {
    [$owner, $store] = saspWorkspace();
    $shopify = saspShopifyConnection($store, 'sasp1-shop.myshopify.com', ['location_id' => '900001']);
    $product = saspSimpleProduct($store, 'SASP-1');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sasp-1-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '20001'],
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['sasp1-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 22]], 200)]);

    $response = $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 22, 'reason' => 'Recount',
    ])->assertSessionHasNoErrors();

    $item = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(22);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/inventory_levels/set.json') && $r['available'] === 22 && $r['inventory_item_id'] === '20001');

    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'Stock updated locally.') && str_contains($message, 'Shopify: synced.'));
});

it('keeps the local inventory correct when the Shopify push fails — no rollback (test 7)', function (): void {
    [$owner, $store] = saspWorkspace();
    $shopify = saspShopifyConnection($store, 'sasp7-shop.myshopify.com', ['location_id' => '900007']);
    $product = saspSimpleProduct($store, 'SASP-7');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sasp-7-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '20007'],
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['sasp7-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'Server error'], 500)]);

    $response = $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 9, 'reason' => 'Recount',
    ])->assertSessionHasNoErrors();

    $item = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    // Local inventory reflects the new quantity regardless of the remote failure.
    expect($balance->on_hand)->toBe(9);

    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'Stock updated locally.') && str_contains($message, 'Shopify stock sync failed'));
});

it('reports "No Shopify location found for inventory sync." when no location can be resolved (test 9)', function (): void {
    [$owner, $store] = saspWorkspace();
    $shopify = saspShopifyConnection($store, 'sasp9-shop.myshopify.com'); // no location metadata
    $product = saspSimpleProduct($store, 'SASP-9');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sasp-9-remote',
        'sync_status' => 'synced', 'metadata' => ['default_inventory_item_id' => '20009'],
    ]));
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['sasp9-shop.myshopify.com/admin/api/*/locations.json' => Http::response(['locations' => []], 200)]);

    $response = $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 3, 'reason' => 'Recount',
    ])->assertSessionHasNoErrors();

    $item = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(3);
    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'No Shopify location found for inventory sync.'));
});

function saspLinkedVariant(Store $store, PlatformConnection $shopify, string $sku, string $inventoryItemId): ProductVariant
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Push Variant Widget', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 45,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 45, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => "{$sku}-remote", 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => "{$sku}-variant-remote",
        'external_inventory_item_id' => $inventoryItemId, 'sync_status' => 'synced',
    ]));

    return $variant->fresh();
}

it('pushes a variant stock adjustment to Shopify and reports it synced', function (): void {
    [$owner, $store] = saspWorkspace();
    $shopify = saspShopifyConnection($store, 'sasp-var1-shop.myshopify.com', ['location_id' => '900101']);
    $variant = saspLinkedVariant($store, $shopify, 'SASP-VAR-1', '20101');
    $product = $variant->product;
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['sasp-var1-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['inventory_level' => ['available' => 13]], 200)]);

    $response = $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 13, 'reason' => 'Recount', 'variant_id' => $variant->id,
    ])->assertSessionHasNoErrors();

    $item = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $variant->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(13);
    Http::assertSent(fn ($r) => $r['inventory_item_id'] === '20101' && $r['available'] === 13);
    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'Shopify: synced.'));
});

it('keeps the variant local inventory correct when the Shopify push fails — no rollback (test 7, variant)', function (): void {
    [$owner, $store] = saspWorkspace();
    $shopify = saspShopifyConnection($store, 'sasp-var7-shop.myshopify.com', ['location_id' => '900107']);
    $variant = saspLinkedVariant($store, $shopify, 'SASP-VAR-7', '20107');
    $product = $variant->product;
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['sasp-var7-shop.myshopify.com/admin/api/*/inventory_levels/set.json' => Http::response(['errors' => 'Server error'], 500)]);

    $response = $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 6, 'reason' => 'Recount', 'variant_id' => $variant->id,
    ])->assertSessionHasNoErrors();

    $item = VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()->where('product_variant_id', $variant->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(6);
    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'Shopify stock sync failed'));
});

it('does not push to Shopify when the product has no Shopify listing at all', function (): void {
    [$owner, $store] = saspWorkspace();
    $product = saspSimpleProduct($store, 'SASP-NONE');
    $warehouse = $store->getPrimaryWarehouse();

    $response = $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 5, 'reason' => 'Recount',
    ])->assertSessionHasNoErrors();

    Http::assertNothingSent();
    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'No Shopify listing for this store'));
});
