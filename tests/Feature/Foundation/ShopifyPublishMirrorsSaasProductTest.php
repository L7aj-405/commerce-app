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
 * The SaaS Product is the source of truth: publishing must mirror its
 * simple/variable structure onto Shopify, whether that means a plain
 * product with a single default variant or a full options+variants payload
 * — driven only by canonical ProductAttribute/ProductAttributeValue/
 * ProductVariant data, never a legacy JSON attributes column.
 */

/** @return array{0: User, 1: Store} */
function spmWorkspace(string $name = 'Publish Mirror Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function spmShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function spmSimpleProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mirror Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 45,
    ]));
}

function spmVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mirror Variable Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 45,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Color', 'values' => ['Black', 'White']],
    ], [
        ['sku' => "{$sku}-BLK", 'price' => 45, 'options' => ['Color' => 'Black']],
        ['sku' => "{$sku}-WHT", 'price' => 47, 'options' => ['Color' => 'White']],
    ]);

    return $product->fresh();
}

it('publishes a SaaS simple product as a simple Shopify product with no options key (test 7)', function (): void {
    [$owner, $store] = spmWorkspace();
    $shopify = spmShopifyConnection($store, 'spm1-shop.myshopify.com');
    $product = spmSimpleProduct($store, 'SPM-1');

    Http::fake(['spm1-shop.myshopify.com/admin/api/*/products.json' => Http::response(['product' => ['id' => 'spm-1-remote']], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/products.json')
        && ! isset($r['product']['options'])
        && count($r['product']['variants']) === 1);

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->value('external_product_id')))->toBe('spm-1-remote');
});

it('publishes a SaaS variable product with canonical options and variants (test 8)', function (): void {
    [$owner, $store] = spmWorkspace();
    $shopify = spmShopifyConnection($store, 'spm2-shop.myshopify.com');
    $product = spmVariableProduct($store, 'SPM-2');

    Http::fake(['spm2-shop.myshopify.com/admin/api/*/products.json' => Http::response([
        'product' => [
            'id' => 'spm-2-remote',
            'variants' => [
                ['id' => 70001, 'option1' => 'Black', 'sku' => 'SPM-2-BLK'],
                ['id' => 70002, 'option1' => 'White', 'sku' => 'SPM-2-WHT'],
            ],
        ],
    ], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/products.json')
        && ($r['product']['options'][0]['name'] ?? null) === 'Color'
        && count($r['product']['variants']) === 2
        && ($r['product']['variants'][0]['option1'] ?? null) !== null);

    $variantListings = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->get());
    expect($variantListings)->toHaveCount(2)
        ->and($variantListings->pluck('external_variant_id')->sort()->values()->all())->toBe(['70001', '70002']);
});

it('publishing to a brand new Shopify connection creates the product, its variants, and both mappings (test 10)', function (): void {
    [$owner, $store] = spmWorkspace();
    $shopify = spmShopifyConnection($store, 'spm3-shop.myshopify.com');
    $product = spmVariableProduct($store, 'SPM-3');

    // No ProductChannelListing exists yet for this connection.
    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->where('product_id', $product->id)->exists()))->toBeFalse();

    Http::fake(['spm3-shop.myshopify.com/admin/api/*/products.json' => Http::response([
        'product' => [
            'id' => 'spm-3-remote',
            'variants' => [
                ['id' => 71001, 'option1' => 'Black', 'sku' => 'SPM-3-BLK'],
                ['id' => 71002, 'option1' => 'White', 'sku' => 'SPM-3-WHT'],
            ],
        ],
    ], 201)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
        'create_missing_listings' => true,
    ])->assertOk();

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->first());

    expect($listing)->not->toBeNull()
        ->and($listing->external_product_id)->toBe('spm-3-remote')
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2)
        // Test 11 — no duplicate local Product created by the publish itself.
        ->and(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        // Test 12 — exactly one ProductChannelListing per (product, connection).
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
            ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->count()))->toBe(1);
});

it('keeps exactly one ProductChannelListing per (product, connection) across repeated publishes (test 12)', function (): void {
    [$owner, $store] = spmWorkspace();
    $shopify = spmShopifyConnection($store, 'spm4-shop.myshopify.com');
    $product = spmSimpleProduct($store, 'SPM-4');

    Http::fake([
        'spm4-shop.myshopify.com/admin/api/*/products.json' => Http::response(['product' => ['id' => 'spm-4-remote']], 201),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id], 'create_missing_listings' => true,
    ])->assertOk();

    Http::fake([
        'spm4-shop.myshopify.com/admin/api/*/products/spm-4-remote.json' => Http::response(['product' => ['id' => 'spm-4-remote']], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
        ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->count()))->toBe(1);
});
