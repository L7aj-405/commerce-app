<?php

declare(strict_types=1);

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
use App\Services\Inventory\OrderLineInventoryResolution;
use App\Services\Inventory\OrderLineInventoryResolver;
use App\Services\OrganizationProvisioner;

/**
 * OrderLineInventoryResolver — the single, platform-agnostic resolver for
 * "what local product/variant/InventoryItem does this online order line
 * actually mean", called with plain external_product_id/external_variant_id/
 * sku strings (never raw platform payloads) so the same rules apply
 * regardless of which connector produced them.
 */

/** @return array{0: User, 1: Store, 2: string} organization id included — resolve() needs it */
function oliWorkspace(string $name = 'Resolver Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization->id];
}

function oliConnection(Store $store, string $platform = 'woocommerce'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => $platform, 'status' => 'active',
    ]));
}

function oliVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Resolver Item', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 60,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 60, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 62, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

it('resolves via variant channel listing (tier 1)', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);
    $product = oliVariableProduct($store, 'OLI-1');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'OLI-1-S')->firstOrFail());

    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'remote-p-1', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => 'remote-v-1', 'sync_status' => 'synced',
    ]));

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'remote-p-1', 'remote-v-1', null);

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_VARIANT_LISTING)
        ->and($resolution->productId)->toBe($product->id)
        ->and($resolution->productVariantId)->toBe($variant->id)
        ->and($resolution->isMapped())->toBeTrue();
});

it('resolves a simple product via product channel listing', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store, 'shopify');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Simple Item', 'sku' => 'OLI-2', 'type' => 'simple', 'status' => 'active', 'price' => 40,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'remote-p-2', 'sync_status' => 'synced',
    ]));

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'remote-p-2', null, null);

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_PRODUCT_LISTING_SIMPLE)
        ->and($resolution->productId)->toBe($product->id)
        ->and($resolution->productVariantId)->toBeNull()
        ->and($resolution->isMapped())->toBeTrue();
});

it('resolves a variable product line by unique SKU when the variant listing is missing', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);
    $product = oliVariableProduct($store, 'OLI-3');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'OLI-3-M')->firstOrFail());

    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'remote-p-3', 'sync_status' => 'synced',
    ]));
    // Deliberately NO ProductVariantChannelListing — the platform sent this
    // line without a resolvable variant id, but its SKU is unambiguous.

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'remote-p-3', null, 'OLI-3-M');

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_PRODUCT_LISTING_VARIANT)
        ->and($resolution->productVariantId)->toBe($variant->id)
        ->and($resolution->isMapped())->toBeTrue();
});

it('never falls back to a stale product-level inventory item for a variable product with no resolvable variant', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);
    $product = oliVariableProduct($store, 'OLI-4');

    // A stale product-level link, exactly the shape that used to get
    // silently (and wrongly) reused.
    $staleItem = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::create([
        'organization_id' => $org, 'sku' => 'OLI-4-STALE', 'name' => 'Stale', 'is_active' => true,
    ]));
    ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::create([
        'organization_id' => $org, 'inventory_item_id' => $staleItem->id, 'product_id' => $product->id, 'units_per_sale' => 1,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'remote-p-4', 'sync_status' => 'synced',
    ]));

    // No variant id, no SKU — nothing to resolve a specific variant from.
    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'remote-p-4', null, null);

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_UNMAPPED)
        ->and($resolution->isMapped())->toBeFalse()
        ->and($resolution->inventoryItem)->toBeNull()
        ->and($resolution->mappingMessage)->toContain('variant');
});

it('falls back to SKU only when it is unique in the store', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Unique Sku Item', 'sku' => 'OLI-5-UNIQUE', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'never-seen-remote', null, 'OLI-5-UNIQUE');

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_SKU_PRODUCT_FALLBACK)
        ->and($resolution->productId)->toBe($product->id)
        ->and($resolution->isMapped())->toBeTrue();
});

it('stays unmapped on a blank SKU', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);
    Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Existing', 'sku' => 'OLI-6-EXISTING', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'unseen-remote', null, '');

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_UNMAPPED)
        ->and($resolution->isMapped())->toBeFalse();
});

it('stays unmapped (ambiguous) when more than one product/variant shares the SKU', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);

    $a = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'A', 'sku' => 'OLI-7-A', 'type' => 'variable', 'status' => 'active', 'price' => 20]));
    app(ProductVariantWizardService::class)->sync($a, [['name' => 'Size', 'values' => ['M']]], [['sku' => 'OLI-7-SHARED', 'price' => 20, 'options' => ['Size' => 'M']]]);

    $b = Product::withoutTenancy(fn () => Product::create(['store_id' => $store->id, 'name' => 'B', 'sku' => 'OLI-7-B', 'type' => 'variable', 'status' => 'active', 'price' => 22]));
    app(ProductVariantWizardService::class)->sync($b, [['name' => 'Size', 'values' => ['M']]], [['sku' => 'OLI-7-SHARED', 'price' => 22, 'options' => ['Size' => 'M']]]);

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'unseen-remote', null, 'OLI-7-SHARED');

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_AMBIGUOUS)
        ->and($resolution->isMapped())->toBeFalse()
        ->and($resolution->productId)->toBeNull();
});

it('gives a clear, specific message for a fully unmapped line', function (): void {
    [, $store, $org] = oliWorkspace();
    $connection = oliConnection($store);

    $resolution = app(OrderLineInventoryResolver::class)->resolve($org, $store->id, $connection->id, 'totally-unknown', null, 'NEVER-SEEN-SKU');

    expect($resolution->mappingSource)->toBe(OrderLineInventoryResolution::SOURCE_UNMAPPED)
        ->and($resolution->mappingMessage)->not->toBeNull()
        ->and($resolution->mappingMessage)->not->toBe('');
});
