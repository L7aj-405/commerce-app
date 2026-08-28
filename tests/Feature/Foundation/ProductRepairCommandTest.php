<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;

/**
 * Phase S7 — catalog:diagnose-product / catalog:repair-product. Diagnostics
 * are read-only; repair only ever archives stale simple-product options and
 * variants (never hard-deletes, never touches external mappings) and
 * defaults to dry-run — --apply is required to actually write anything.
 */

/** @return array{0: User, 1: Store} */
function prcWorkspace(string $name = 'Product Repair Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function prcWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

function prcShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

it('reports no issues for a clean simple product', function (): void {
    [, $store] = prcWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Clean Simple', 'sku' => 'PRC-CLEAN-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $this->artisan('catalog:diagnose-product', ['product_id' => $product->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('No issues found');
});

it('fails with a clear error for an unknown product id', function (): void {
    $this->artisan('catalog:diagnose-product', ['product_id' => '01HNOTREALPRODUCTID000000'])
        ->assertExitCode(1)
        ->expectsOutputToContain('not found');
});

it('detects ghost variants and active options left on a simple product', function (): void {
    [, $store] = prcWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Reverted To Simple', 'sku' => 'PRC-GHOST-1', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Red']],
    ], [
        ['sku' => 'PRC-GHOST-1-RED', 'price' => 30, 'options' => ['Color' => 'Red']],
    ]);

    // Simulate the corruption: an external sync (or a bug) flips the product
    // back to simple WITHOUT archiving its options/variants.
    $product->update(['type' => 'simple']);

    $this->artisan('catalog:diagnose-product', ['product_id' => $product->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('ghost_variants_on_simple_product')
        ->expectsOutputToContain('active_options_on_simple_product');
});

it('detects a variable-product variant with no canonical option pivots', function (): void {
    [, $store] = prcWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Missing Pivots', 'sku' => 'PRC-PIVOT-1', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    // Created directly, bypassing the wizard — no attribute_values pivot ever attached.
    ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $product->id, 'name' => 'Orphan Variant', 'sku' => 'PRC-PIVOT-1-A', 'price' => 30,
    ]));

    $this->artisan('catalog:diagnose-product', ['product_id' => $product->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('variant_missing_option_pivots');
});

it('detects a simple product Shopify listing missing default-variant metadata', function (): void {
    [, $store] = prcWorkspace();
    $shopify = prcShopifyConnection($store, 'prc3-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'No Default Meta', 'sku' => 'PRC-META-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'prc-meta-remote',
        'sync_status' => 'synced', 'metadata' => [],
    ]));

    $this->artisan('catalog:diagnose-product', ['product_id' => $product->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('missing_shopify_default_variant_metadata');
});

it('detects a Shopify variant listing missing external_inventory_item_id', function (): void {
    [, $store] = prcWorkspace();
    $shopify = prcShopifyConnection($store, 'prc4-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'No Inv Item', 'sku' => 'PRC-INV-1', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'PRC-INV-1-S', 'price' => 30, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'prc-inv-remote', 'sync_status' => 'synced',
    ]));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => 'prc-inv-variant-remote', 'sync_status' => 'synced',
    ]));

    $this->artisan('catalog:diagnose-product', ['product_id' => $product->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('missing_variant_inventory_item_id');
});

it('does not change anything in dry-run mode (the default)', function (): void {
    [, $store] = prcWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Dry Run Ghost', 'sku' => 'PRC-DRYRUN-1', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Blue']],
    ], [
        ['sku' => 'PRC-DRYRUN-1-BLUE', 'price' => 30, 'options' => ['Color' => 'Blue']],
    ]);
    $product->update(['type' => 'simple']);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $this->artisan('catalog:repair-product', ['product_id' => $product->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('Would apply: archive_simple_product_options_and_variants')
        ->expectsOutputToContain('Dry run only');

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('id', $variant->id)->exists()))->toBeTrue();
    expect(ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()->where('value', 'Blue')->where('is_active', true)->exists()))->toBeTrue();
});

it('archives ghost variants and options for a simple product when --apply is passed', function (): void {
    [, $store] = prcWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Apply Ghost', 'sku' => 'PRC-APPLY-1', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Green']],
    ], [
        ['sku' => 'PRC-APPLY-1-GREEN', 'price' => 30, 'options' => ['Color' => 'Green']],
    ]);
    $product->update(['type' => 'simple']);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $this->artisan('catalog:repair-product', ['product_id' => $product->id, '--apply' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Applied: archive_simple_product_options_and_variants');

    // Archived, never hard-deleted.
    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()->where('id', $variant->id)->first())->trashed())->toBeTrue();
    expect(ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()->where('value', 'Green')->where('is_active', true)->exists()))->toBeFalse();

    $this->artisan('catalog:diagnose-product', ['product_id' => $product->id])
        ->expectsOutputToContain('No issues found');
});

it('leaves unresolved issues (like missing Shopify default-variant metadata) unrepaired since repair only ever archives simple-product ghost state', function (): void {
    [, $store] = prcWorkspace();
    $shopify = prcShopifyConnection($store, 'prc5-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Unfixable Meta', 'sku' => 'PRC-KEEP-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'prc-keep-remote',
        'sync_status' => 'synced', 'metadata' => [],
    ]));

    $this->artisan('catalog:repair-product', ['product_id' => $product->id, '--apply' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('No safe automatic repairs are applicable')
        ->expectsOutputToContain('missing_shopify_default_variant_metadata');

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->exists()))->toBeTrue();
});
