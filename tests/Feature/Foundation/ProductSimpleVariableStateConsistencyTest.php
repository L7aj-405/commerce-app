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
use App\Services\Publishing\ProductOptionSnapshot;
use App\Services\Publishing\ProductPublishReadinessService;

/**
 * product.type is authoritative: when a product becomes simple — whether
 * through the Product Edit form or (covered in ShopifySimpleProductReadinessTest)
 * an external sync — its old canonical options/variants must be archived,
 * never left active to leak into readiness/publish/edit-props.
 */

/** @return array{0: User, 1: Store} */
function svcWorkspace(string $name = 'State Consistency Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function svcVariableProduct(Store $store, string $sku): Product
{
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Convertible Product', 'sku' => $sku, 'type' => 'variable', 'status' => 'active', 'price' => 30,
    ]));

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['S', 'M']],
    ], [
        ['sku' => "{$sku}-S", 'price' => 30, 'options' => ['Size' => 'S']],
        ['sku' => "{$sku}-M", 'price' => 32, 'options' => ['Size' => 'M']],
    ]);

    return $product->fresh();
}

it('archives active options when a variable product is switched to simple in Product Edit', function (): void {
    [$owner, $store] = svcWorkspace();
    $product = svcVariableProduct($store, 'SVC-1');

    expect(ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()
        ->whereHas('attribute', fn ($q) => $q->where('product_id', $product->id))
        ->where('is_active', true)->count()))->toBe(2);

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'simple', 'price' => 30, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()
        ->whereHas('attribute', fn ($q) => $q->where('product_id', $product->id))
        ->where('is_active', true)->count()))->toBe(0)
        ->and(ProductOptionSnapshot::build($product->fresh())['options'])->toBe([]);
});

it('archives (soft-deletes, never hard-deletes) active variants when switched to simple', function (): void {
    [$owner, $store] = svcWorkspace();
    $product = svcVariableProduct($store, 'SVC-2');

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'simple', 'price' => 30, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(0)
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()->where('product_id', $product->id)->count()))->toBe(2);
});

it('does not surface archived options/variants in Product Edit active props', function (): void {
    [$owner, $store] = svcWorkspace();
    $product = svcVariableProduct($store, 'SVC-3');

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'simple', 'price' => 30, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Products/Edit')
            ->where('product.type', 'simple')
            ->where('product.options', [])
            ->where('product.variants', [])
            ->where('readiness.shopify.status', 'ready')
            ->where('readiness.woocommerce.status', 'ready'));
});

it('still blocks Shopify readiness for a variable product with a variant missing an option value', function (): void {
    [, $store] = svcWorkspace();
    $product = svcVariableProduct($store, 'SVC-4');

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $variant->attributeValues()->detach();

    $report = app(ProductPublishReadinessService::class)->shopify($product->fresh());

    expect($report['status'])->toBe('blocked')
        ->and(implode(' ', $report['errors']))->toContain('missing option values');
});

it('lets a simple product switch back to variable through Generate variants, even after a prior archive', function (): void {
    [$owner, $store] = svcWorkspace();
    $product = svcVariableProduct($store, 'SVC-5');

    // Simulate the product having been published before archiving — the
    // protected-value path (archive keeps the row instead of hard-deleting)
    // is the realistic scenario this must still work under.
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token', 'status' => 'active',
        'shop_domain' => 'svc5-shop.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'svc-5-remote', 'sync_status' => 'synced',
    ]));
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->where('sku', 'SVC-5-S')->firstOrFail());
    ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $connection->id, 'external_variant_id' => '90001', 'sync_status' => 'synced',
    ]));

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'simple', 'price' => 30, 'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($product->fresh()->type)->toBe('simple');

    // Now re-generate variants — the wizard's own save path, exactly what
    // the "Generate variants" UI action submits.
    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => $product->name, 'sku' => $product->sku, 'type' => 'variable', 'price' => 30, 'status' => 'active',
        'options' => [['name' => 'Size', 'values' => ['S', 'M', 'L']]],
        'variants' => [
            ['sku' => 'SVC-5-S', 'price' => 30, 'options' => ['Size' => 'S']],
            ['sku' => 'SVC-5-M', 'price' => 32, 'options' => ['Size' => 'M']],
            ['sku' => 'SVC-5-L', 'price' => 34, 'options' => ['Size' => 'L']],
        ],
    ])->assertSessionHasNoErrors();

    $fresh = $product->fresh();
    $snapshot = ProductOptionSnapshot::build($fresh);

    expect($fresh->type)->toBe('variable')
        ->and($snapshot['options'])->toHaveCount(1)
        ->and($snapshot['options'][0]['values'])->toBe(['S', 'M', 'L'])
        ->and($snapshot['variants'])->toHaveCount(3)
        ->and(app(ProductPublishReadinessService::class)->shopify($fresh)['status'])->toBe('ready');
});
