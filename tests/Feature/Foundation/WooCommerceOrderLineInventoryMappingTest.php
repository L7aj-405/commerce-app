<?php

declare(strict_types=1);

use App\Connectors\WooCommerceConnector;
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
 * WooCommerce order line -> local product/variant/InventoryItem mapping.
 *
 * Root-cause regression: WooCommerceConnector::parseOrder() never read
 * `variation_id` off a WooCommerce line item at all, so a variable
 * product's order line only ever carried the parent `product_id` — no
 * variant identifier ever reached OrderLineItems/the resolver, no matter how
 * correctly ProductVariantChannelListing was set up. That's exactly why a
 * waiting order never released after the matching variant's stock was
 * topped up: the shortage was never linked to the variant's InventoryItem
 * to begin with.
 */

/** @return array{0: User, 1: Store, 2: PlatformConnection} */
function wooliWorkspace(string $name = 'WooCommerce Mapping Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => 'https://woolitest.example.com', 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));

    return [$owner, $store, $connection];
}

function wooliVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'WooCommerce Item', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 70,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 70, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 72, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

function wooliOrder(Store $store, PlatformConnection $connection, array $items): App\Models\Order
{
    return App\Models\Order::factory()->create([
        'store_id' => $store->id, 'platform_connection_id' => $connection->id,
        'order_number' => 'WOOLI-' . fake()->unique()->numerify('#####'), 'items' => $items,
    ]);
}

it('the WooCommerce connector emits variant_id from variation_id on every order line (regression guard)', function (): void {
    [, , $connection] = wooliWorkspace();

    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::response([[
            'id' => 8001,
            'number' => '8001',
            'status' => 'processing',
            'total' => '72.00',
            'subtotal' => '72.00',
            'total_tax' => '0.00',
            'shipping_total' => '0.00',
            'discount_total' => '0.00',
            'billing' => ['first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'c@example.com', 'phone' => ''],
            'line_items' => [[
                'id' => 1, 'product_id' => 900, 'variation_id' => 901, 'sku' => 'jkrt44', 'name' => 'WooCommerce Item',
                'quantity' => 1, 'price' => 72, 'total' => '72.00',
            ]],
            'date_created' => now()->toIso8601String(),
        ]], 200),
    ]);

    $orders = (new WooCommerceConnector($connection))->getOrders();

    expect($orders)->toHaveCount(1)
        ->and($orders[0]['items'][0]['variant_id'] ?? null)->toBe('901')
        ->and($orders[0]['items'][0]['product_id'] ?? null)->toBe('900')
        ->and($orders[0]['items'][0]['sku'] ?? null)->toBe('jkrt44');
});

it('the WooCommerce connector never emits a variant_id for a simple-product line (variation_id 0)', function (): void {
    [, , $connection] = wooliWorkspace();

    Http::fake([
        '*/wp-json/wc/v3/orders*' => Http::response([[
            'id' => 8002, 'number' => '8002', 'status' => 'processing', 'total' => '50.00', 'subtotal' => '50.00',
            'total_tax' => '0.00', 'shipping_total' => '0.00', 'discount_total' => '0.00',
            'billing' => ['first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'c@example.com', 'phone' => ''],
            'line_items' => [[
                'id' => 2, 'product_id' => 902, 'variation_id' => 0, 'sku' => 'SIMPLE-1', 'name' => 'Simple Item',
                'quantity' => 1, 'price' => 50, 'total' => '50.00',
            ]],
            'date_created' => now()->toIso8601String(),
        ]], 200),
    ]);

    $orders = (new WooCommerceConnector($connection))->getOrders();

    expect($orders[0]['items'][0]['variant_id'] ?? null)->toBe('');
});

it('maps a WooCommerce variation_id to the local variant via ProductVariantChannelListing', function (): void {
    [, $store, $connection] = wooliWorkspace();
    $product = wooliVariableProduct($store, 'WOOLI-1');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WOOLI-1-S')->firstOrFail());

    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => '900', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => '901', 'sync_status' => 'synced',
    ]));

    $order = wooliOrder($store, $connection, [[
        'product_id' => '900', 'variant_id' => '901', 'sku' => 'jkrt44', 'name' => 'WooCommerce Item',
        'quantity' => 1, 'price' => 70, 'total' => 70,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['product_id'])->toBe($product->id)
        ->and($line['variant_id'])->toBe($variant->id)
        ->and($line['inventory_item_id'])->not->toBeNull()
        ->and($line['unmapped'])->toBeFalse();
});

it('maps WooCommerce product_id + SKU to the local variant when the variant listing is missing', function (): void {
    [, $store, $connection] = wooliWorkspace();
    $product = wooliVariableProduct($store, 'WOOLI-2');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'WOOLI-2-M')->firstOrFail());

    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => '910', 'sync_status' => 'synced',
    ]));
    // No ProductVariantChannelListing at all — exactly the reported bug
    // shape: product_id resolves, variant_id came back empty/unmapped, but
    // the SKU uniquely identifies the variant.

    $order = wooliOrder($store, $connection, [[
        'product_id' => '910', 'sku' => 'WOOLI-2-M', 'name' => 'WooCommerce Item',
        'quantity' => 1, 'price' => 72, 'total' => 72,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['variant_id'])->toBe($variant->id)
        ->and($line['inventory_item_id'])->not->toBeNull()
        ->and($line['unmapped'])->toBeFalse();
});

it('maps a WooCommerce simple product to its product inventory item', function (): void {
    [, $store, $connection] = wooliWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Woo Simple', 'sku' => 'WOOLI-3', 'type' => 'simple', 'status' => 'active', 'price' => 45,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => '920', 'sync_status' => 'synced',
    ]));

    $order = wooliOrder($store, $connection, [[
        'product_id' => '920', 'name' => 'Woo Simple', 'quantity' => 1, 'price' => 45, 'total' => 45,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['product_id'])->toBe($product->id)
        ->and($line['variant_id'])->toBeNull()
        ->and($line['inventory_item_id'])->not->toBeNull()
        ->and($line['unmapped'])->toBeFalse();
});

it('never falls back to a stale product-level inventory link for a WooCommerce variable line', function (): void {
    [, $store, $connection] = wooliWorkspace();
    $product = wooliVariableProduct($store, 'WOOLI-4');

    $staleItem = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::create([
        'organization_id' => $store->organization_id, 'sku' => 'WOOLI-4-STALE', 'name' => 'Stale', 'is_active' => true,
    ]));
    ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::create([
        'organization_id' => $store->organization_id, 'inventory_item_id' => $staleItem->id, 'product_id' => $product->id, 'units_per_sale' => 1,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => '930', 'sync_status' => 'synced',
    ]));

    // No variant id, and a SKU that matches nothing on this product.
    $order = wooliOrder($store, $connection, [[
        'product_id' => '930', 'sku' => 'NEVER-MATCHES', 'name' => 'WooCommerce Item',
        'quantity' => 1, 'price' => 70, 'total' => 70,
    ]]);

    $line = OrderLineItems::for($order)[0];

    expect($line['inventory_item_id'])->toBeNull()
        ->and($line['inventory_item_id'])->not->toBe($staleItem->id)
        ->and($line['unmapped'])->toBeTrue();
});
