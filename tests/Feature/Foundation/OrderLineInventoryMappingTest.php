<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Support\OrderLineItems;

/**
 * Phase O4 — order line inventory resolution: ProductVariantChannelListing
 * by (connection, external_variant_id) first, then ProductChannelListing by
 * (connection, external_product_id), then a safe SKU fallback ONLY when it
 * maps to exactly one local product/variant in the store — never a silent
 * guess. A line that carried a real identifier and matched nothing is
 * flagged `unmapped`, never silently treated as "nothing to move."
 */

/** @return array{0: User, 1: Store} */
function olimWorkspace(string $name = 'Line Mapping Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function olimConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));
}

function olimOrder(Store $store, PlatformConnection $connection, array $items): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'platform_connection_id' => $connection->id,
        'order_number' => 'OLIM-' . fake()->unique()->numerify('#####'),
        'items' => $items,
    ]);
}

it('maps an online line to a local product via ProductChannelListing external_product_id', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim1-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mapped Product', 'sku' => 'OLIM-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'olim-1-remote', 'sync_status' => 'synced',
    ]));

    $order = olimOrder($store, $connection, [[
        'product_id' => 'olim-1-remote', 'name' => 'Mapped Product', 'quantity' => 2, 'unit_price' => 20, 'line_total' => 40,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBe($product->id)
        ->and($lines[0]['unmapped'])->toBeFalse();
});

it('maps an online line to a local variant via ProductVariantChannelListing external_variant_id', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim2-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Product', 'sku' => 'OLIM-2', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'OLIM-2-S', 'price' => 30, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'olim-2-remote', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'olim-2-var-remote', 'sync_status' => 'synced',
    ]));

    $order = olimOrder($store, $connection, [[
        'product_id' => 'olim-2-remote', 'variant_id' => 'olim-2-var-remote', 'name' => 'Variant Product',
        'quantity' => 1, 'unit_price' => 30, 'line_total' => 30,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBe($product->id)
        ->and($lines[0]['variant_id'])->toBe($variant->id)
        ->and($lines[0]['unmapped'])->toBeFalse();
});

it('falls back to SKU only when it is unique in the store', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim3-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Sku Fallback Product', 'sku' => 'OLIM-3-UNIQUE', 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ]));

    // No channel listing at all — the platform sent an external id we've
    // never seen, but the SKU uniquely matches a local product.
    $order = olimOrder($store, $connection, [[
        'product_id' => 'never-seen-remote-id', 'sku' => 'OLIM-3-UNIQUE', 'name' => 'Sku Fallback Product',
        'quantity' => 1, 'unit_price' => 25, 'line_total' => 25,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBe($product->id)
        ->and($lines[0]['unmapped'])->toBeFalse();
});

it('does not merge when the SKU is blank', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim4-woo.example.com');
    Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Existing', 'sku' => 'OLIM-4-EXISTING', 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ]));

    $order = olimOrder($store, $connection, [[
        'product_id' => 'unseen-remote-id', 'sku' => '', 'name' => 'Blank Sku Item',
        'quantity' => 1, 'unit_price' => 25, 'line_total' => 25,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBeNull()
        ->and($lines[0]['unmapped'])->toBeTrue();
});

it('does not merge when more than one product/variant in the store shares the SKU', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim5-woo.example.com');

    $productA = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Product A', 'sku' => 'OLIM-5-A', 'type' => 'variable', 'status' => 'active', 'price' => 20,
    ]));
    app(ProductVariantWizardService::class)->sync($productA, [
        ['name' => 'Size', 'values' => ['M']],
    ], [
        ['sku' => 'OLIM-5-SHARED', 'price' => 20, 'options' => ['Size' => 'M']],
    ]);

    $productB = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Product B', 'sku' => 'OLIM-5-B', 'type' => 'variable', 'status' => 'active', 'price' => 22,
    ]));
    app(ProductVariantWizardService::class)->sync($productB, [
        ['name' => 'Size', 'values' => ['M']],
    ], [
        ['sku' => 'OLIM-5-SHARED', 'price' => 22, 'options' => ['Size' => 'M']],
    ]);

    $order = olimOrder($store, $connection, [[
        'product_id' => 'unseen-remote-id', 'sku' => 'OLIM-5-SHARED', 'name' => 'Ambiguous Sku Item',
        'quantity' => 1, 'unit_price' => 20, 'line_total' => 20,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBeNull()
        ->and($lines[0]['unmapped'])->toBeTrue();
});

it('flags an unmapped stock-required line explicitly instead of silently skipping it', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim6-woo.example.com');

    $order = olimOrder($store, $connection, [[
        'product_id' => 'totally-unknown-remote-id', 'sku' => 'NEVER-SEEN-SKU', 'name' => 'Ghost Item',
        'quantity' => 1, 'unit_price' => 20, 'line_total' => 20,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBeNull()
        ->and($lines[0]['unmapped'])->toBeTrue();
});

it('does not flag a genuinely custom/service line that never carried any product identifier', function (): void {
    [, $store] = olimWorkspace();
    $connection = olimConnection($store, 'olim7-woo.example.com');

    $order = olimOrder($store, $connection, [[
        'name' => 'Custom engraving fee', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
    ]]);

    $lines = OrderLineItems::for($order);

    expect($lines[0]['product_id'])->toBeNull()
        ->and($lines[0]['unmapped'])->toBeFalse();
});
