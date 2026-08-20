<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\OrganizationProvisioner;
use App\Services\Publishing\ProductPublishReadinessService;

/** @return array{0: User, 1: Store} */
function readinessWorkspace(string $name = 'Readiness Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function readinessProduct(Store $store, string $sku, string $type = 'simple'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Readiness Product', 'sku' => $sku, 'type' => $type, 'status' => 'active', 'price' => 50,
    ]));
}

it('marks a simple product ready for both platforms', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-SIMPLE-1');

    $report = app(ProductPublishReadinessService::class)->check($product);

    expect($report['shopify']['status'])->toBe('ready')
        ->and($report['woocommerce']['status'])->toBe('ready');
});

it('marks a Shopify product with 4 options as blocked', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-4OPT-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
        ['name' => 'Color', 'values' => ['Black']],
        ['name' => 'Material', 'values' => ['Cotton']],
        ['name' => 'Fit', 'values' => ['Slim']],
    ], [
        ['sku' => 'READY-4OPT-1', 'price' => 50, 'options' => ['Size' => 'X', 'Color' => 'Black', 'Material' => 'Cotton', 'Fit' => 'Slim']],
    ]);

    $report = app(ProductPublishReadinessService::class)->check($product->fresh());

    expect($report['shopify']['status'])->toBe('blocked')
        ->and(implode(' ', $report['shopify']['errors']))->toContain('maximum of 3 variant options');
});

it('marks a product missing variant option values as blocked for Shopify', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-MISSING-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X']],
    ], [
        ['sku' => 'READY-MISSING-1', 'price' => 50, 'options' => ['Size' => 'X']],
    ]);

    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());
    $variant->attributeValues()->detach();

    $report = app(ProductPublishReadinessService::class)->check($product->fresh());

    expect($report['shopify']['status'])->toBe('blocked')
        ->and(implode(' ', $report['shopify']['errors']))->toContain('missing option values');
});

it('passes a WooCommerce variable product with valid attributes and variants', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-WOO-OK-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
    ], [
        ['sku' => 'READY-WOO-OK-1-X', 'price' => 50, 'options' => ['Size' => 'X']],
        ['sku' => 'READY-WOO-OK-1-XL', 'price' => 50, 'options' => ['Size' => 'XL']],
    ]);

    $report = app(ProductPublishReadinessService::class)->check($product->fresh());

    expect($report['woocommerce']['status'])->toBe('ready');
});

it('blocks a WooCommerce variable product with no variants yet', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-WOO-NOVAR-1', 'variable');

    $report = app(ProductPublishReadinessService::class)->check($product);

    expect($report['woocommerce']['status'])->toBe('blocked')
        ->and(implode(' ', $report['woocommerce']['errors']))->toContain('at least one variant');
});

it('blocks both platforms when duplicate variant option combinations exist', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-DUPE-1', 'variable');

    app(ProductVariantWizardService::class)->sync($product, [
        ['name' => 'Size', 'values' => ['X', 'XL']],
    ], [
        ['sku' => 'READY-DUPE-1-A', 'price' => 50, 'options' => ['Size' => 'X']],
        ['sku' => 'READY-DUPE-1-B', 'price' => 50, 'options' => ['Size' => 'XL']],
    ]);

    // Force a duplicate combination directly against the canonical pivot —
    // the wizard itself already rejects duplicates at save time, so this
    // simulates data that reached the DB some other way (e.g. legacy import).
    $variants = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->get());
    $xlValueIds = $variants->last()->attributeValues()->pluck('id')->all();
    $variants->first()->attributeValues()->sync($xlValueIds);

    $report = app(ProductPublishReadinessService::class)->check($product->fresh());

    expect($report['shopify']['status'])->toBe('blocked')
        ->and($report['woocommerce']['status'])->toBe('blocked')
        ->and(implode(' ', $report['shopify']['errors']))->toContain('duplicate variant option combinations');
});

it('passes readiness for a simple product regardless of platform', function (): void {
    [, $store] = readinessWorkspace();
    $product = readinessProduct($store, 'READY-SIMPLE-2');

    $shopify = app(ProductPublishReadinessService::class)->shopify($product);
    $woocommerce = app(ProductPublishReadinessService::class)->woocommerce($product);

    expect($shopify['status'])->toBe('ready')
        ->and($woocommerce['status'])->toBe('ready')
        ->and($shopify['errors'])->toBe([])
        ->and($woocommerce['errors'])->toBe([]);
});
