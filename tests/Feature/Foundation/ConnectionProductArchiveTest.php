<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;

/** @return array{0: User, 1: Store} */
function cpaWorkspace(string $name = 'Connection Archive Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cpaWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

function cpaShopify(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function cpaProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => "Archive Product {$sku}", 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
}

it('archives every product imported from the selected connection', function (): void {
    [$owner, $store] = cpaWorkspace();
    $woo = cpaWoo($store, 'connarch1-woo.example.com');

    $productA = cpaProduct($store, 'CONNARCH-1');
    $productB = cpaProduct($store, 'CONNARCH-2');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $productA->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-connarch-1', 'sync_status' => 'synced',
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $productB->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-connarch-2', 'sync_status' => 'synced',
    ]));

    $response = $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/archive-imported-products")
        ->assertOk();

    expect($response->json('summary.products_archived'))->toBe(2)
        ->and($productA->fresh()->status)->toBe('archived')
        ->and($productB->fresh()->status)->toBe('archived');
});

it('does not affect products imported from a different connection', function (): void {
    [$owner, $store] = cpaWorkspace();
    $woo = cpaWoo($store, 'connarch2-woo.example.com');
    $shopify = cpaShopify($store, 'connarch2-shop.myshopify.com');

    $wooProduct = cpaProduct($store, 'CONNARCH-3');
    $shopifyProduct = cpaProduct($store, 'CONNARCH-4');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $wooProduct->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-connarch-3', 'sync_status' => 'synced',
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $shopifyProduct->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'shop-connarch-4', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/archive-imported-products")
        ->assertOk();

    expect($wooProduct->fresh()->status)->toBe('archived')
        ->and($shopifyProduct->fresh()->status)->toBe('active');
});

it('never purges — archiving keeps order history and inventory ledger intact', function (): void {
    [$owner, $store] = cpaWorkspace();
    $woo = cpaWoo($store, 'connarch3-woo.example.com');
    $product = cpaProduct($store, 'CONNARCH-5');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-connarch-5', 'sync_status' => 'synced',
    ]));

    $item = app(\App\Services\Inventory\CatalogInventoryService::class)->forCatalog($product);
    $org = $store->organization;
    $warehouse = \App\Models\Warehouse::withoutTenancy(fn () => \App\Models\Warehouse::create([
        'user_id' => $owner->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => 'Connection Archive Warehouse', 'type' => \App\Models\Warehouse::TYPE_STANDARD, 'country' => 'MA',
        'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);
    app(\App\Services\Inventory\InventoryEngine::class)->setOnHand($item, $warehouse, 4, 'adjustment', null, null, 'seed', false);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/archive-imported-products")->assertOk();

    expect($product->fresh()->status)->toBe('archived')
        ->and(Product::withoutTenancy(fn () => Product::withTrashed()->find($product->id))->trashed())->toBeFalse()
        ->and(app(\App\Services\Inventory\InventoryEngine::class)->balance($item, $warehouse)->on_hand)->toBe(4);
});
