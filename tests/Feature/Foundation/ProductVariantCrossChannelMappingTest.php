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

/** @return array{0: User, 1: Store} */
function xchanVariantWorkspace(string $name = 'Cross Channel Variant Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function xchanVariantWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

function xchanVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Variant Mapping Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 50,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S']],
    ], [
        ["sku" => "{$sku}-S", 'price' => 50, 'options' => ['Size' => 'S']],
    ]);

    return $product->fresh();
}

it('saves a ProductVariantChannelListing when a remote variation is created', function (): void {
    [$owner, $store] = xchanVariantWorkspace();
    $woo = xchanVariantWoo($store, 'vx1-woo.example.com');
    $product = xchanVariableProduct($store, 'VX1');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    Http::fake([
        'vx1-woo.example.com/wp-json/wc/v3/products' => Http::response(['id' => 7001], 201),
        'vx1-woo.example.com/wp-json/wc/v3/products/7001/variations' => Http::response(['id' => 7101], 201),
    ]);

    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
        'create_missing_listings' => true,
    ])->assertOk();

    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
        ->where('product_variant_id', $variant->id)->where('platform_connection_id', $woo->id)->first());

    expect($variantListing)->not->toBeNull()
        ->and($variantListing->external_variant_id)->toBe('7101');
});

it('updates the same remote variation on a later publish instead of creating a duplicate', function (): void {
    [$owner, $store] = xchanVariantWorkspace();
    $woo = xchanVariantWoo($store, 'vx2-woo.example.com');
    $product = xchanVariableProduct($store, 'VX2');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    Http::fake([
        'vx2-woo.example.com/wp-json/wc/v3/products' => Http::response(['id' => 7002], 201),
        'vx2-woo.example.com/wp-json/wc/v3/products/7002/variations' => Http::response(['id' => 7102], 201),
    ]);
    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id], 'create_missing_listings' => true,
    ])->assertOk();

    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $variant->id)->count()))->toBe(1);

    Http::fake([
        'vx2-woo.example.com/wp-json/wc/v3/products/7002' => Http::response(['id' => 7002], 200),
        'vx2-woo.example.com/wp-json/wc/v3/products/7002/variations/7102' => Http::response(['id' => 7102], 200),
    ]);
    $this->actingAs($owner)->postJson("/dashboard/products/{$product->id}/publish", [
        'connection_ids' => [$woo->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/products/7002/variations/7102'));
    expect(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $variant->id)->count()))->toBe(1);
});

it('attaches synced remote variations to existing local variants by variant listing first', function (): void {
    [, $store] = xchanVariantWorkspace();
    $woo = xchanVariantWoo($store, 'vx3-woo.example.com');
    $product = xchanVariableProduct($store, 'VX3');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    // Simulate the variant already being linked (e.g. from a prior publish),
    // with a SKU that will be DIFFERENT in the incoming sync payload.
    $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::updateOrCreate(
        ['product_id' => $product->id, 'platform_connection_id' => $woo->id],
        ['external_product_id' => 'woo-parent-3', 'sync_status' => 'synced'],
    ));
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'product_channel_listing_id' => $productListing->id,
        'platform_connection_id' => $woo->id, 'external_variant_id' => '7103', 'sync_status' => 'synced',
    ]));

    app(ProductSyncService::class)->createVariants($product, [
        ['external_id' => '7103', 'name' => 'Small (renamed on platform)', 'sku' => 'VX3-S-RENAMED', 'price' => 55, 'attributes' => ['Size' => 'S']],
    ], $woo);

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(1)
        ->and($variant->fresh()->sku)->toBe('VX3-S-RENAMED');
});

it('uses SKU fallback for a variant only when unique within the same parent product', function (): void {
    [, $store] = xchanVariantWorkspace();
    $woo = xchanVariantWoo($store, 'vx4-woo.example.com');
    $product = xchanVariableProduct($store, 'VX4');
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    // A variant listing can only ever hang off a real parent product
    // listing — establish that first, exactly like saveProduct() always
    // does before it ever calls createVariants().
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-parent-4', 'sync_status' => 'synced',
    ]));

    // No ProductVariantChannelListing yet — sync must fall back to matching
    // by SKU within this exact product, and it IS unique here.
    app(ProductSyncService::class)->createVariants($product, [
        ['external_id' => '7104', 'name' => 'Small', 'sku' => "VX4-S", 'price' => 60, 'attributes' => ['Size' => 'S']],
    ], $woo);

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(1)
        ->and($variant->fresh()->external_id)->toBe('7104');

    $listing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->where('product_variant_id', $variant->id)->first());
    expect($listing)->not->toBeNull()
        ->and($listing->external_variant_id)->toBe('7104');

    // A second, unrelated product's variant with a different SKU must never
    // be matched by this fallback — parent-product scoping stays intact.
    $otherProduct = xchanVariableProduct($store, 'VX4-OTHER');
    $otherVariant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $otherProduct->id)->firstOrFail());
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $otherProduct->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-parent-4-other', 'sync_status' => 'synced',
    ]));

    app(ProductSyncService::class)->createVariants($otherProduct, [
        ['external_id' => '7105', 'name' => 'Small', 'sku' => 'VX4-OTHER-S', 'price' => 60, 'attributes' => ['Size' => 'S']],
    ], $woo);

    expect($otherVariant->fresh()->external_id)->toBe('7105')
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(1);
});
