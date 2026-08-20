<?php

declare(strict_types=1);

use App\Models\InventoryLedgerEntry;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Models\WarehouseInventoryBalance;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\ProductSyncService;
use Illuminate\Support\Facades\Http;

function wooOutboundWorkspace(string $name = 'Woo Outbound Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'woocommerce',
        'status' => 'active',
        'api_url' => 'https://woo-outbound-test.example.com',
        'consumer_key' => 'ck_test',
        'consumer_secret' => 'cs_test',
    ]));

    return [$owner, $store, $connection];
}

function wooOutboundProduct(Store $store, PlatformConnection $connection, string $sku = 'WOO-OUT-1', string $externalId = '501'): array
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Outbound Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ]));

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id,
        'platform_connection_id' => $connection->id,
        'external_product_id' => $externalId,
        'sync_status' => 'synced',
    ]));

    return [$product, $listing];
}

it('does not remove the WooCommerce listing when name/sku are edited', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product, $listing] = wooOutboundProduct($store, $connection);

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Renamed Product', 'sku' => 'WOO-OUT-1-RENAMED', 'type' => 'simple', 'price' => 30, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->not->toBeNull()
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id))->external_product_id)->toBe('501');
});

it('publishes product changes to WooCommerce using the existing remote product id, without duplicating the listing', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product] = wooOutboundProduct($store, $connection);

    Http::fake([
        'woo-outbound-test.example.com/wp-json/wc/v3/products/501' => Http::response(['id' => 501], 200),
    ]);

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/push")->assertRedirect();

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/products/501')
        && ! str_contains($request->url(), 'variations'));

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->count()))->toBe(1);
});

it('publishes a variant using its remote variation id', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product, $listing] = wooOutboundProduct($store, $connection, 'WOO-VAR-PARENT', '601');
    $product->update(['type' => 'variable']);

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Red / M', 'sku' => 'WOO-VAR-1', 'price' => 40, 'attributes' => ['Color' => 'Red'],
    ]));

    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id,
        'external_variant_id' => '7001',
        'sync_status' => 'synced',
    ]));

    Http::fake([
        'woo-outbound-test.example.com/wp-json/wc/v3/products/601' => Http::response(['id' => 601], 200),
        'woo-outbound-test.example.com/wp-json/wc/v3/products/601/variations/7001' => Http::response(['id' => 7001], 200),
    ]);

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/push")->assertRedirect();

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_contains($request->url(), '/products/601/variations/7001'));
});

it('does not let a product from Store A be pushed through Store Bs connection', function (): void {
    [, $storeA, $connectionA] = wooOutboundWorkspace('Store A Woo');
    [$ownerB] = wooOutboundWorkspace('Store B Woo');
    [$productA] = wooOutboundProduct($storeA, $connectionA);

    $this->actingAs($ownerB)->post("/dashboard/products/{$productA->id}/push")->assertNotFound();
});

it('cannot update quantity by blindly writing product.quantity through the edit form', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product] = wooOutboundProduct($store, $connection);

    $warehouse = $store->getPrimaryWarehouse();
    Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'variant_id' => null, 'quantity' => 12, 'reorder_level' => 10]);

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'simple', 'price' => 25, 'status' => 'active', 'qty' => 999,
    ])->assertSessionHasNoErrors();

    expect((int) Stock::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'))->toBe(12);
});

it('updates WarehouseInventoryBalance and writes an InventoryLedgerEntry on stock adjustment', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product] = wooOutboundProduct($store, $connection);
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['woo-outbound-test.example.com/*' => Http::response(['id' => 501], 200)]);

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 40, 'reason' => 'Physical recount',
    ])->assertSessionHasNoErrors();

    $item = \App\Models\ProductInventoryLink::withoutOrganizationTenancy(fn () => \App\Models\ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(40)
        ->and(InventoryLedgerEntry::withoutOrganizationTenancy(fn () => InventoryLedgerEntry::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->exists()))->toBeTrue();
});

it('pushes the adjusted quantity to WooCommerce using the existing listing', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product] = wooOutboundProduct($store, $connection);
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake([
        'woo-outbound-test.example.com/wp-json/wc/v3/products/501' => Http::response(['id' => 501], 200),
    ]);

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 17, 'reason' => 'Recount',
    ])->assertSessionHasNoErrors();

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/products/501')
        && ($request['stock_quantity'] ?? null) === 17);
});

it('keeps local inventory correct and marks the listing failed when the WooCommerce stock push fails', function (): void {
    [$owner, $store, $connection] = wooOutboundWorkspace();
    [$product, $listing] = wooOutboundProduct($store, $connection);
    $warehouse = $store->getPrimaryWarehouse();

    Http::fake(['woo-outbound-test.example.com/*' => Http::response(['message' => 'Server error'], 500)]);

    $this->actingAs($owner)->post("/dashboard/products/{$product->id}/stock", [
        'warehouse_id' => $warehouse->id, 'type' => 'set_on_hand', 'quantity' => 9, 'reason' => 'Recount',
    ])->assertSessionHasNoErrors();

    $item = \App\Models\ProductInventoryLink::withoutOrganizationTenancy(fn () => \App\Models\ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail())->inventoryItem;
    $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->firstOrFail());

    expect($balance->on_hand)->toBe(9)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id))->sync_status)->toBe('failed');
});

it('still pulls from WooCommerce correctly after the outbound sync changes', function (): void {
    [, $store, $connection] = wooOutboundWorkspace('Pull Regression Store');
    $sync = app(ProductSyncService::class);

    expect($sync->saveProduct([
        'external_id' => 'pull-900',
        'name' => 'Pulled product',
        'sku' => 'PULL-900',
        'type' => 'simple',
        'status' => 'active',
        'price' => 55,
        'stock' => 3,
    ], $store, 'woocommerce', $connection))->toBe('created');

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'PULL-900')->firstOrFail());

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->where('external_product_id', 'pull-900')->exists()))->toBeTrue();
});
