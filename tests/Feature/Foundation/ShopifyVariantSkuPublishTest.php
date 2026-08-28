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
use App\Services\Sync\ProductSyncService;
use Illuminate\Support\Facades\Http;

/**
 * For a variable product, SKU lives on each Shopify variant — publishing
 * must update every mapped remote variant's SKU, and syncing must read each
 * variant's SKU back into the matching local ProductVariant.
 */

/** @return array{0: User, 1: Store} */
function svspWorkspace(string $name = 'Variant Sku Publish Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function svspShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

it('updates each mapped remote Shopify variant SKU when publishing a variable product (test 6)', function (): void {
    [$owner, $store] = svspWorkspace();
    $shopify = svspShopifyConnection($store, 'svsp1-shop.myshopify.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Sku Product', 'sku' => 'VSKU-1', 'type' => 'variable', 'status' => 'active', 'price' => 50,
    ]));
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => 'VSKU-1-S-NEW', 'price' => 50, 'options' => ['Size' => 'S']],
        ['sku' => 'VSKU-1-M-NEW', 'price' => 52, 'options' => ['Size' => 'M']],
    ]);
    $product = $product->fresh();

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'vsku-1-remote', 'sync_status' => 'synced',
    ]));

    $variants = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->orderBy('sku')->get());
    $externalIds = ['VSKU-1-M-NEW' => '61001', 'VSKU-1-S-NEW' => '61002'];

    foreach ($variants as $variant) {
        ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
            'platform_connection_id' => $shopify->id, 'external_variant_id' => $externalIds[$variant->sku], 'sync_status' => 'synced',
        ]));
    }

    Http::fake(['svsp1-shop.myshopify.com/admin/api/*/products/vsku-1-remote.json' => Http::response(['product' => [
        'id' => 'vsku-1-remote',
        'variants' => [
            ['id' => 61002, 'option1' => 'S', 'sku' => 'VSKU-1-S-NEW'],
            ['id' => 61001, 'option1' => 'M', 'sku' => 'VSKU-1-M-NEW'],
        ],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    // A single combined parent PUT carries BOTH variant skus, each targeted
    // by its known remote id — Shopify updates them in place.
    Http::assertSentCount(1);
    Http::assertSent(function ($r) {
        $variants = collect($r['product']['variants'])->keyBy('id');

        return $r->method() === 'PUT'
            && $variants->get('61001')['sku'] === 'VSKU-1-M-NEW'
            && $variants->get('61002')['sku'] === 'VSKU-1-S-NEW';
    });
});

it('imports each Shopify variant SKU into the matching local ProductVariant on sync (test 9)', function (): void {
    [, $store] = svspWorkspace();
    $shopify = svspShopifyConnection($store, 'svsp2-shop.myshopify.com');

    Http::fake(['svsp2-shop.myshopify.com/admin/api/*/products.json*' => Http::response([
        'products' => [[
            'id' => 'vsku-2-remote',
            'title' => 'Imported Variable Product',
            'body_html' => '',
            'status' => 'active',
            'options' => [['name' => 'Size', 'position' => 1]],
            'variants' => [
                ['id' => 71001, 'sku' => 'IMPORTED-S', 'price' => '20.00', 'option1' => 'S'],
                ['id' => 71002, 'sku' => 'IMPORTED-M', 'price' => '22.00', 'option1' => 'M'],
            ],
        ]],
    ], 200)]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'shopify');

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('name', 'Imported Variable Product')->first());
    expect($product)->not->toBeNull()
        ->and($product->type)->toBe('variable');

    $skus = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->pluck('sku')->sort()->values()->all());
    expect($skus)->toBe(['IMPORTED-M', 'IMPORTED-S']);
});
