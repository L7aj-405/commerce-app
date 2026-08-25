<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Http;

/** @return array{0: User, 1: Store} */
function csrWorkspace(string $name = 'Sync Reset Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function csrWoo(Store $store, string $domain, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ], $overrides)));
}

function csrShopify(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

it('resets product mappings for only the selected connection', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset1-woo.example.com');
    $shopify = csrShopify($store, 'reset1-shop.myshopify.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Reset Product', 'sku' => 'RESET-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $wooListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-reset-1', 'sync_status' => 'synced',
    ]));
    $shopifyListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'shop-reset-1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)
        ->postJson("/dashboard/integrations/connections/{$woo->id}/reset-product-mappings")
        ->assertOk()
        ->assertJsonPath('summary.products_affected', 1);

    // WooCommerce mapping is gone; Shopify's is untouched (test #4).
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($wooListing->id)))->toBeNull()
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($shopifyListing->id)))->not->toBeNull();

    // The local product is untouched (test #5).
    expect(Product::withoutTenancy(fn () => Product::query()->find($product->id)))->not->toBeNull()
        ->and($product->fresh()->trashed())->toBeFalse();
});

it('cascades the mapping reset to the variant listing for that connection only', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset2-woo.example.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Reset Variant Product', 'sku' => 'RESET-2', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $variant = \App\Models\ProductVariant::withoutTenancy(fn () => \App\Models\ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Only Variant', 'sku' => 'RESET-2-V1', 'price' => 20, 'cost' => 0,
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-reset-2', 'sync_status' => 'synced',
    ]));
    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $woo->id, 'external_variant_id' => 'woo-reset-2-v1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-product-mappings")->assertOk();

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->find($variantListing->id)))->toBeNull()
        ->and(\App\Models\ProductVariant::withoutTenancy(fn () => \App\Models\ProductVariant::query()->find($variant->id)))->not->toBeNull();
});

it('does not delete inventory when resetting product mappings', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset3-woo.example.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Reset Inventory Product', 'sku' => 'RESET-3', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-reset-3', 'sync_status' => 'synced',
    ]));

    $org = $store->organization;
    $warehouse = \App\Models\Warehouse::withoutTenancy(fn () => \App\Models\Warehouse::create([
        'user_id' => $owner->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => 'Reset Inventory Warehouse', 'type' => \App\Models\Warehouse::TYPE_STANDARD, 'country' => 'MA',
        'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 7, 'adjustment', null, null, 'seed', false);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-product-mappings")->assertOk();

    $balance = app(InventoryEngine::class)->balance($item, $warehouse);
    expect($balance->on_hand)->toBe(7);
});

it('resets the product sync cursor without touching mappings', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset4-woo.example.com', [
        'metadata' => ['product_sync' => ['last_synced_at' => now()->toIso8601String(), 'last_error' => null]],
    ]);

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Cursor Product', 'sku' => 'RESET-4', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-reset-4', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-product-cursor")->assertOk();

    expect($woo->fresh()->metadata['product_sync'] ?? null)->toBeNull()
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->not->toBeNull();
});

it('resets the order sync cursor without deleting existing orders', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset5-woo.example.com', [
        'metadata' => ['order_sync' => ['last_synced_at' => now()->toIso8601String(), 'last_error' => null]],
    ]);

    $order = app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'RESET-5-ORDER', 'number' => '#1', 'status' => 'processing', 'total' => 50.0, 'currency' => 'MAD',
        'customer_name' => 'Customer', 'customer_email' => null, 'customer_phone' => null, 'items' => [],
        'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $woo);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-order-cursor")->assertOk();

    expect($woo->fresh()->metadata['order_sync'] ?? null)->toBeNull()
        ->and(Order::withoutTenancy(fn () => Order::query()->find($order->id)))->not->toBeNull();
});

it('does not delete credentials when resetting mappings or cursors', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset6-woo.example.com');

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-product-mappings")->assertOk();
    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-product-cursor")->assertOk();
    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/reset-order-cursor")->assertOk();

    $fresh = $woo->fresh();
    expect($fresh->api_url)->toBe('https://reset6-woo.example.com')
        ->and($fresh->consumer_key)->toBe('ck_test')
        ->and($fresh->consumer_secret)->toBe('cs_test')
        ->and($fresh->status)->toBe('active');
});

it('re-syncs after an order cursor reset by updating the existing order, never duplicating it', function (): void {
    [$owner, $store] = csrWorkspace();
    $woo = csrWoo($store, 'reset7-woo.example.com');
    $base = "/dashboard/integrations/connections/{$woo->id}";

    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::sequence()
            ->push([[
                'id' => 9001, 'number' => '1001', 'status' => 'processing', 'total' => '100.00', 'currency' => 'MAD',
                'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String(),
            ]], 200)
            ->push([], 200) // page 2 — stop the pull loop
            ->push([[
                'id' => 9001, 'number' => '1001', 'status' => 'processing', 'total' => '150.00', 'currency' => 'MAD',
                'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String(),
            ]], 200)
            ->push([], 200),
    ]);

    $this->actingAs($owner)->postJson("{$base}/sync-orders")->assertOk();
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1);

    $this->actingAs($owner)->postJson("{$base}/reset-order-cursor")->assertOk();

    $this->actingAs($owner)->postJson("{$base}/sync-orders")->assertOk();

    $orders = Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->get());
    expect($orders)->toHaveCount(1)
        ->and($orders->first()->platform_order_id)->toBe('9001')
        ->and((float) $orders->first()->total)->toBe(150.0);
});
