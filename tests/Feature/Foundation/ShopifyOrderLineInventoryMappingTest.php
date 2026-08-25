<?php

declare(strict_types=1);

use App\Connectors\ShopifyConnector;
use App\Models\InventoryItem;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Support\OrderLineItems;
use Illuminate\Support\Facades\Http;

/**
 * Shopify order line -> local product/variant/InventoryItem mapping, via the
 * same OrderLineInventoryResolver every platform shares. Shopify already
 * emitted `variant_id` correctly (ShopifyConnector::parseOrder) before this
 * fix — these tests lock that in and add the "never falls back to a stale
 * product-level item" guarantee the resolver now enforces for every platform.
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function soliWorkspace(string $name = 'Shopify Mapping Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'status' => 'active',
        'shop_domain' => 'soli-test.myshopify.com', 'access_token' => 'shpat_test',
    ]));

    return [$owner, $store, $connection];
}

function soliVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Shopify Item', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 80,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 80, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 82, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

function soliOrder(Store $store, PlatformConnection $connection, array $items): App\Models\Order
{
    return App\Models\Order::factory()->create([
        'store_id' => $store->id, 'platform_connection_id' => $connection->id,
        'order_number' => 'SOLI-' . fake()->unique()->numerify('#####'), 'items' => $items,
    ]);
}

it('maps a Shopify variable order line by ProductVariantChannelListing', function (): void {
    [, $store, $connection] = soliWorkspace();
    $product = soliVariableProduct($store, 'SOLI-1');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'SOLI-1-S')->firstOrFail());

    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'shop-prod-1', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'shop-var-1', 'sync_status' => 'synced',
    ]));

    // Mirrors ShopifyConnector::parseOrder()'s line_items shape.
    $order = soliOrder($store, $connection, [[
        'product_id' => 'shop-prod-1', 'variant_id' => 'shop-var-1', 'name' => 'Shopify Item',
        'quantity' => 1, 'price' => 80, 'total' => 80,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['product_id'])->toBe($product->id)
        ->and($line['variant_id'])->toBe($variant->id)
        ->and($line['inventory_item_id'])->not->toBeNull()
        ->and($line['unmapped'])->toBeFalse();
});

it('maps a Shopify simple order line to the product/default inventory item', function (): void {
    [, $store, $connection] = soliWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Shopify Simple', 'sku' => 'SOLI-2', 'type' => 'simple', 'status' => 'active', 'price' => 50,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'shop-prod-2', 'sync_status' => 'synced',
        'metadata' => ['default_variant_id' => 'shop-default-var-2', 'default_inventory_item_id' => 'shop-remote-inv-2'],
    ]));

    // Shopify always sends a variant_id even for a "simple" product (its own
    // default variant) — the resolver must still land on the SIMPLE product,
    // never try to treat this as a real local variant.
    $order = soliOrder($store, $connection, [[
        'product_id' => 'shop-prod-2', 'variant_id' => 'shop-default-var-2', 'name' => 'Shopify Simple',
        'quantity' => 1, 'price' => 50, 'total' => 50,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['product_id'])->toBe($product->id)
        ->and($line['variant_id'])->toBeNull()
        ->and($line['inventory_item_id'])->not->toBeNull()
        ->and($line['unmapped'])->toBeFalse();
});

it('maps a Shopify variable line by unique SKU when the variant listing is missing', function (): void {
    [, $store, $connection] = soliWorkspace();
    $product = soliVariableProduct($store, 'SOLI-3');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'SOLI-3-M')->firstOrFail());

    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'shop-prod-3', 'sync_status' => 'synced',
    ]));

    $order = soliOrder($store, $connection, [[
        'product_id' => 'shop-prod-3', 'variant_id' => 'shop-var-never-synced', 'sku' => 'SOLI-3-M', 'name' => 'Shopify Item',
        'quantity' => 1, 'price' => 82, 'total' => 82,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['variant_id'])->toBe($variant->id)
        ->and($line['inventory_item_id'])->not->toBeNull()
        ->and($line['unmapped'])->toBeFalse();
});

it('never falls back to a stale product-level inventory link for a Shopify variable line', function (): void {
    [$owner, $store, $connection] = soliWorkspace();
    $product = soliVariableProduct($store, 'SOLI-4');

    $staleItem = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::create([
        'organization_id' => $store->organization_id, 'sku' => 'SOLI-4-STALE', 'name' => 'Stale', 'is_active' => true,
    ]));
    ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::create([
        'organization_id' => $store->organization_id, 'inventory_item_id' => $staleItem->id, 'product_id' => $product->id, 'units_per_sale' => 1,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'shop-prod-4', 'sync_status' => 'synced',
    ]));

    // No resolvable variant identifier at all — must stay unmapped, never
    // silently attach to the stale product-level item above.
    $order = soliOrder($store, $connection, [[
        'product_id' => 'shop-prod-4', 'variant_id' => 'shop-var-unknown', 'name' => 'Shopify Item',
        'quantity' => 1, 'price' => 80, 'total' => 80,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['inventory_item_id'])->toBeNull()
        ->and($line['inventory_item_id'])->not->toBe($staleItem->id)
        ->and($line['unmapped'])->toBeTrue()
        ->and($line['mapping_message'])->toContain('variant');
});

it('the Shopify connector itself emits variant_id on every order line (regression guard)', function (): void {
    [, $store, $connection] = soliWorkspace();

    Http::fake([
        '*/admin/api/*/orders.json*' => Http::response([
            'orders' => [[
                'id' => 555001,
                'order_number' => 1001,
                'financial_status' => 'paid',
                'total_price' => '80.00',
                'currency' => 'MAD',
                'customer' => ['first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'c@example.com'],
                'line_items' => [[
                    'id' => 999501, 'product_id' => 'shop-prod-5', 'variant_id' => 'shop-var-5', 'sku' => 'SOLI-5-S',
                    'name' => 'Shopify Item', 'quantity' => 1, 'price' => '80.00',
                ]],
                'created_at' => now()->toIso8601String(),
            ]],
        ], 200),
    ]);

    $orders = (new ShopifyConnector($connection))->getOrders();

    expect($orders)->toHaveCount(1)
        ->and($orders[0]['items'][0]['variant_id'] ?? null)->toBe('shop-var-5')
        ->and($orders[0]['items'][0]['product_id'] ?? null)->toBe('shop-prod-5');
});
