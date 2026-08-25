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
use App\Services\Publishing\ProductPublishReadinessService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Support\Facades\Http;

/**
 * A product previously tested as variable in SaaS/Shopify, whose
 * options/variants were later removed on the Shopify side, must behave as
 * simple everywhere once synced back — readiness, publish payload, and the
 * local canonical state — never blocked by stale leftover options/variants.
 */

/** @return array{0: User, 1: Store} */
function ssprWorkspace(string $name = 'Shopify Simple Readiness Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ssprShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function ssprSimpleProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Simple Widget', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 25,
    ]));
}

/**
 * A product previously tested as variable AND already published to Shopify —
 * has active canonical options/variants plus a ProductChannelListing +
 * ProductVariantChannelListing rows, exactly the "tested as variable" state
 * the bug report describes.
 */
function ssprPublishedVariableProduct(Store $store, PlatformConnection $shopify, string $externalId, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Previously Variable Widget', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 25,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 25, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 27, 'options' => ['Size' => 'M']],
    ]);

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => $externalId, 'sync_status' => 'synced',
    ]));

    foreach (ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->get()) as $i => $variant) {
        ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
            'platform_connection_id' => $shopify->id, 'external_variant_id' => (string) (80000 + $i), 'sync_status' => 'synced',
        ]));
    }

    return $product->fresh();
}

it('is ready for Shopify without requiring any variants when the product is simple (test 4)', function (): void {
    [, $store] = ssprWorkspace();
    $product = ssprSimpleProduct($store, 'SSPR-1');

    $report = app(ProductPublishReadinessService::class)->shopify($product);

    expect($report['status'])->toBe('ready')
        ->and($report['errors'])->toBe([]);
});

it('publishes a simple product without sending an options-only Shopify payload (test 5)', function (): void {
    [$owner, $store] = ssprWorkspace();
    $shopify = ssprShopifyConnection($store, 'sspr2-shop.myshopify.com');
    $product = ssprSimpleProduct($store, 'SSPR-2');
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'sspr-2-remote', 'sync_status' => 'synced',
    ]));

    Http::fake([
        'sspr2-shop.myshopify.com/admin/api/*/products/sspr-2-remote.json' => Http::response(['product' => ['id' => 'sspr-2-remote', 'variants' => [['id' => 50002, 'sku' => 'SSPR-2']]]], 200),
        'sspr2-shop.myshopify.com/admin/api/*/variants/50002.json' => Http::response(['variant' => ['id' => 50002, 'sku' => 'SSPR-2']], 200),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    // Shopify SKU lives on the variant, not the parent — the parent payload
    // must never carry an options key OR an id-less variants array (either
    // risks a 422 or a silently duplicated default variant). The SKU is
    // updated via a separate, explicit id-targeted variant call instead
    // (see ShopifySimpleSkuPublishTest for that behavior in depth).
    Http::assertSent(function ($r) {
        if ($r->method() !== 'PUT' || ! str_contains($r->url(), '/products/sspr-2-remote.json')) {
            return false;
        }

        $body = $r['product'];

        return ! array_key_exists('options', $body) && ! array_key_exists('variants', $body);
    });
});

it('sets local product.type to simple when Shopify sync returns a default-variant product (test 6)', function (): void {
    [, $store] = ssprWorkspace();
    $shopify = ssprShopifyConnection($store, 'sspr3-shop.myshopify.com');
    $product = ssprPublishedVariableProduct($store, $shopify, 'sspr-3-remote', 'SSPR-3');

    expect($product->type)->toBe('variable');

    Http::fake(['sspr3-shop.myshopify.com/admin/api/*/products.json*' => Http::response([
        'products' => [[
            'id' => 'sspr-3-remote',
            'title' => 'Previously Variable Widget',
            'body_html' => '',
            'status' => 'active',
            'variants' => [[
                'id' => 90001,
                'sku' => 'SSPR-3',
                'price' => '25.00',
            ]],
        ]],
    ], 200)]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'shopify');

    expect($product->fresh()->type)->toBe('simple');
});

it('archives old local options/variants from the previous variable state on Shopify sync (test 7)', function (): void {
    [, $store] = ssprWorkspace();
    $shopify = ssprShopifyConnection($store, 'sspr4-shop.myshopify.com');
    $product = ssprPublishedVariableProduct($store, $shopify, 'sspr-4-remote', 'SSPR-4');

    Http::fake(['sspr4-shop.myshopify.com/admin/api/*/products.json*' => Http::response([
        'products' => [[
            'id' => 'sspr-4-remote',
            'title' => 'Previously Variable Widget',
            'body_html' => '',
            'status' => 'active',
            'variants' => [[
                'id' => 90002,
                'sku' => 'SSPR-4',
                'price' => '25.00',
            ]],
        ]],
    ], 200)]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'shopify');

    expect(ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()
        ->whereHas('attribute', fn ($q) => $q->where('product_id', $product->id))
        ->where('is_active', true)->count()))->toBe(0)
        // The two ORIGINAL variable-state variants are archived, not
        // hard-deleted — ProductVariantChannelListing keeps their history.
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::query()
            ->where('product_id', $product->id)->where('sku', '!=', 'SSPR-4')->count()))->toBe(0)
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()
            ->where('product_id', $product->id)->where('sku', 'like', 'SSPR-4-%')->count()))->toBe(2);
});

it('does not let old archived ProductVariantChannelListing mappings block simple readiness (test 8)', function (): void {
    [, $store] = ssprWorkspace();
    $shopify = ssprShopifyConnection($store, 'sspr5-shop.myshopify.com');
    $product = ssprPublishedVariableProduct($store, $shopify, 'sspr-5-remote', 'SSPR-5');

    Http::fake(['sspr5-shop.myshopify.com/admin/api/*/products.json*' => Http::response([
        'products' => [[
            'id' => 'sspr-5-remote',
            'title' => 'Previously Variable Widget',
            'body_html' => '',
            'status' => 'active',
            'variants' => [[
                'id' => 90003,
                'sku' => 'SSPR-5',
                'price' => '25.00',
            ]],
        ]],
    ], 200)]);

    app(ProductSyncService::class)->syncFromPlatform($store, 'shopify');

    // The old ProductVariantChannelListing rows (external_variant_id 80000/80001)
    // still exist, pointing at now-archived variants — they must never be
    // consulted for readiness on a product that is simple now.
    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
        ->where('product_id', $product->id)->whereIn('external_variant_id', ['80000', '80001'])->count()))->toBe(2);

    $report = app(ProductPublishReadinessService::class)->shopify($product->fresh());

    expect($report['status'])->toBe('ready')
        ->and($report['errors'])->toBe([]);
});
