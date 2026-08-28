<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * Phase S4 — consolidating regression test for the simple/variable Shopify
 * strategy (the behavior itself was already built across Task 6's Shopify
 * follow-up work; see cerebrum.md "product.type is authoritative..." and
 * "Shopify SKU lives on the VARIANT..." entries). One file stating the five
 * invariants explicitly, end to end:
 *
 *  1. A simple product never gets an active local ProductVariant.
 *  2. Its Shopify default variant identity lives in
 *     ProductChannelListing.metadata (default_variant_id,
 *     default_inventory_item_id), never a ProductVariant row.
 *  3. A variable product uses real ProductVariant + ProductVariantChannelListing rows.
 *  4. variable -> simple archives (soft-deletes) options/variants, never
 *     hard-deletes a variant carrying an external mapping.
 *  5. simple -> variable (user-generated variants + publish) creates the
 *     missing remote variants.
 */

/** @return array{0: User, 1: Store} */
function ssdsWorkspace(string $name = 'Shopify Default Variant Strategy Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ssdsShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

it('a simple product never gets an active local ProductVariant, and its Shopify default variant identity is stored in ProductChannelListing.metadata', function (): void {
    [$owner, $store] = ssdsWorkspace();
    $shopify = ssdsShopifyConnection($store, 'ssds1-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Strategy Simple', 'sku' => 'SSDS-1', 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ]));

    Http::fake(['ssds1-shop.myshopify.com/admin/api/*/products.json' => Http::response(['product' => [
        'id' => 'ssds-1-remote',
        'variants' => [['id' => 40001, 'sku' => 'SSDS-1', 'inventory_item_id' => 50001]],
    ]], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()->where('product_id', $product->id)->count()))->toBe(0);

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->firstOrFail());

    expect($listing->metadata['default_variant_id'] ?? null)->toBe('40001')
        ->and($listing->metadata['default_inventory_item_id'] ?? null)->toBe('50001');
});

it('a variable product uses real ProductVariant + ProductVariantChannelListing rows, not product-level metadata', function (): void {
    [$owner, $store] = ssdsWorkspace();
    $shopify = ssdsShopifyConnection($store, 'ssds2-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Strategy Variable', 'sku' => 'SSDS-2', 'type' => 'variable', 'status' => 'active', 'price' => 35,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => 'SSDS-2-S', 'price' => 35, 'options' => ['Size' => 'S']],
        ['sku' => 'SSDS-2-M', 'price' => 37, 'options' => ['Size' => 'M']],
    ]);

    Http::fake(['ssds2-shop.myshopify.com/admin/api/*/products.json' => Http::response(['product' => [
        'id' => 'ssds-2-remote',
        'variants' => [
            ['id' => 40002, 'option1' => 'S', 'sku' => 'SSDS-2-S'],
            ['id' => 40003, 'option1' => 'M', 'sku' => 'SSDS-2-M'],
        ],
    ]], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    $variantListings = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->get());
    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->firstOrFail());

    expect($variantListings)->toHaveCount(2)
        ->and($variantListings->pluck('external_variant_id')->sort()->values()->all())->toBe(['40002', '40003'])
        ->and($productListing->metadata['default_variant_id'] ?? null)->toBeNull();
});

it('archives (never hard-deletes) options/variants when a published variable product is switched to simple', function (): void {
    [$owner, $store] = ssdsWorkspace();
    $shopify = ssdsShopifyConnection($store, 'ssds3-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Strategy Revert', 'sku' => 'SSDS-3', 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ['sku' => 'SSDS-3-S', 'price' => 30, 'options' => ['Size' => 'S']],
    ]);
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'ssds-3-remote', 'sync_status' => 'synced',
    ]));
    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $shopify->id, 'external_variant_id' => 'ssds-3-var-remote', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'simple', 'price' => 30, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(0)
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()->where('id', $variant->id)->first())->trashed())->toBeTrue()
        // The external mapping is never touched by archiving — it just
        // becomes inert once the product is simple (readiness ignores it
        // via the type check, not by deleting the row).
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('id', $variantListing->id)->exists()))->toBeTrue();
});

it('switching simple to variable via user-generated variants, then publishing, creates the missing remote variants', function (): void {
    [$owner, $store] = ssdsWorkspace();
    $shopify = ssdsShopifyConnection($store, 'ssds4-shop.myshopify.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Strategy Grow', 'sku' => 'SSDS-4', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'ssds-4-remote',
        'sync_status' => 'synced', 'metadata' => ['default_variant_id' => '40004'],
    ]));

    $product->update(['type' => 'variable']);
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => 'SSDS-4-S', 'price' => 20, 'options' => ['Size' => 'S']],
        ['sku' => 'SSDS-4-M', 'price' => 22, 'options' => ['Size' => 'M']],
    ]);

    Http::fake(['ssds4-shop.myshopify.com/admin/api/*/products/ssds-4-remote.json' => Http::response(['product' => [
        'id' => 'ssds-4-remote',
        'variants' => [
            ['id' => 40005, 'option1' => 'S', 'sku' => 'SSDS-4-S'],
            ['id' => 40006, 'option1' => 'M', 'sku' => 'SSDS-4-M'],
        ],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    $variantListings = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->get());

    expect($variantListings)->toHaveCount(2)
        ->and($variantListings->pluck('external_variant_id')->sort()->values()->all())->toBe(['40005', '40006']);
});
