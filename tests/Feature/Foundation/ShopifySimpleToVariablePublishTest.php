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
 * Covers the core bug: a Shopify-imported simple product is converted to
 * variable in the SaaS, options/variants are added, and publishing must
 * mirror the SaaS structure onto the EXISTING remote Shopify product in ONE
 * request — Shopify 422s ("Product options must have corresponding
 * variants") on an options-only update/create, so options and variants must
 * always travel together, never options-first-then-variants-later.
 */

/** @return array{0: User, 1: Store} */
function ss2vWorkspace(string $name = 'Simple To Variable Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ss2vShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

/** A product already imported from Shopify — has a ProductChannelListing, still simple. */
function ss2vImportedSimpleProduct(Store $store, PlatformConnection $shopify, string $externalId, string $sku): Product
{
    app(ProductSyncService::class)->saveProduct([
        'external_id' => $externalId, 'name' => 'Convertible Widget', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ], $store, 'shopify', $shopify);

    return Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', $sku)->firstOrFail());
}

function ss2vMakeVariable(Product $product, string $sku): Product
{
    $product->update(['type' => 'variable']);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 20, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 22, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

it('sends options and variants together in ONE request when converting simple to variable, and saves the returned variant ids', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v1-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-101', 'S2V-1');
    $product = ss2vMakeVariable($product, 'S2V-1');

    Http::fake(['s2v1-shop.myshopify.com/admin/api/*/products/s2v-101.json' => Http::response(['product' => [
        'id' => 's2v-101',
        'variants' => [
            ['id' => 90101, 'option1' => 'S', 'sku' => 'S2V-1-S'],
            ['id' => 90102, 'option1' => 'M', 'sku' => 'S2V-1-M'],
        ],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    // Exactly ONE HTTP call — options and variants travel in the same
    // request, never a separate options-then-variants sequence.
    Http::assertSentCount(1);

    Http::assertSent(function ($r) {
        $product = $r['product'];

        return $r->method() === 'PUT'
            && str_contains($r->url(), '/products/s2v-101.json')
            && ($product['options'][0]['name'] ?? null) === 'Size'
            && count($product['variants']) === 2;
    });

    $variantListings = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
        ->where('product_id', $product->id)->get());

    expect($variantListings)->toHaveCount(2)
        ->and($variantListings->pluck('external_variant_id')->sort()->values()->all())->toBe(['90101', '90102'])
        ->and($variantListings->every(fn ($l) => $l->sync_status === 'synced'))->toBeTrue();
});

it('never sends product options without variant data in the same request', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v2-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-102', 'S2V-2');
    $product = ss2vMakeVariable($product, 'S2V-2');

    Http::fake(['s2v2-shop.myshopify.com/admin/api/*/products/s2v-102.json' => Http::response(['product' => [
        'id' => 's2v-102',
        'variants' => [
            ['id' => 90201, 'option1' => 'S', 'sku' => 'S2V-2-S'],
            ['id' => 90202, 'option1' => 'M', 'sku' => 'S2V-2-M'],
        ],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(function ($r) {
        $product = $r['product'];

        // Whenever "options" is present, "variants" must be present too and
        // non-empty — Shopify 422s on the combination this guards against.
        if (! array_key_exists('options', $product)) {
            return true;
        }

        return array_key_exists('variants', $product) && count($product['variants']) > 0;
    });
});

it('gives each variant option1/option2/option3 in canonical option order', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v3-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-103', 'S2V-3');
    $product->update(['type' => 'variable']);

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
        ['name' => 'Color', 'values' => ['Red']],
    ], [
        ['sku' => 'S2V-3-S-RED', 'price' => 20, 'options' => ['Size' => 'S', 'Color' => 'Red']],
    ]);
    $product = $product->fresh();

    Http::fake(['s2v3-shop.myshopify.com/admin/api/*/products/s2v-103.json' => Http::response(['product' => [
        'id' => 's2v-103',
        'variants' => [['id' => 90301, 'option1' => 'S', 'option2' => 'Red', 'sku' => 'S2V-3-S-RED']],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => ($r['product']['variants'][0]['option1'] ?? null) === 'S'
        && ($r['product']['variants'][0]['option2'] ?? null) === 'Red');
});

it('updates the same mappings on a second publish without creating duplicate ProductVariantChannelListing rows', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v4-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-104', 'S2V-4');
    $product = ss2vMakeVariable($product, 'S2V-4');

    Http::fake(['s2v4-shop.myshopify.com/admin/api/*/products/s2v-104.json' => Http::response(['product' => [
        'id' => 's2v-104',
        'variants' => [
            ['id' => 90401, 'option1' => 'S', 'sku' => 'S2V-4-S'],
            ['id' => 90402, 'option1' => 'M', 'sku' => 'S2V-4-M'],
        ],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2);

    // Second publish — the already-linked variants' remote ids must be
    // attached to the outgoing payload so Shopify updates them in place.
    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertSent(function ($r) {
        $ids = collect($r['product']['variants'])->pluck('id')->sort()->values()->all();

        return $ids === ['90401', '90402'] || $ids === [90401, 90402];
    });

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_id', $product->id)->count()))->toBe(2)
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
            ->where('product_id', $product->id)->pluck('external_variant_id')->sort()->values()->all()))->toBe(['90401', '90402']);
});

it('blocks publish before any HTTP call when the product has options but no active variants', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v5-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-105', 'S2V-5');
    $product->update(['type' => 'variable']);

    // Options defined, but no variants generated yet — exactly the shape
    // that Shopify's 422 ("must have corresponding variants") is about.
    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], []);
    $product = $product->fresh();

    Http::fake(['s2v5-shop.myshopify.com/*' => Http::response(['product' => ['id' => 'should-not-be-called']], 200)]);

    // ProductController::publish() itself already blocks a variable product
    // with zero variants before the service/publisher ever runs.
    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertStatus(422);

    Http::assertNothingSent();
});

it('blocks publish before any HTTP call when a variant is missing an option value', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v6-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-106', 'S2V-6');
    $product = ss2vMakeVariable($product, 'S2V-6');

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $variant->attributeValues()->detach();

    Http::fake(['s2v6-shop.myshopify.com/*' => Http::response(['product' => ['id' => 'should-not-be-called']], 200)]);

    $response = $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    Http::assertNothingSent();
    expect($response->json('results.0.status'))->toBe('failed')
        ->and($response->json('results.0.message'))->toContain('missing option values');
});

it('never creates a duplicate local Product while converting and republishing', function (): void {
    [$owner, $store] = ss2vWorkspace();
    $shopify = ss2vShopifyConnection($store, 's2v7-shop.myshopify.com');
    $product = ss2vImportedSimpleProduct($store, $shopify, 's2v-107', 'S2V-7');
    $product = ss2vMakeVariable($product, 'S2V-7');

    Http::fake(['s2v7-shop.myshopify.com/admin/api/*/products/s2v-107.json' => Http::response(['product' => [
        'id' => 's2v-107',
        'variants' => [
            ['id' => 90701, 'option1' => 'S', 'sku' => 'S2V-7-S'],
            ['id' => 90702, 'option1' => 'M', 'sku' => 'S2V-7-M'],
        ],
    ]], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$shopify->id],
    ])->assertOk();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
            ->where('product_id', $product->id)->where('platform_connection_id', $shopify->id)->count()))->toBe(1);
});
