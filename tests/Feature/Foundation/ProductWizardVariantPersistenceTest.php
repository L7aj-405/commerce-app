<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function wizardPersistenceWorkspace(string $name = 'Wizard Persistence Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('creates a variable product through the wizard with real option/value links, not just a hopeful JSON blob', function (): void {
    [$owner, $store] = wizardPersistenceWorkspace();

    $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'Wizard Shirt', 'sku' => 'WIZ-SHIRT', 'type' => 'variable', 'price' => 100,
        'options' => [
            ['name' => 'Size', 'values' => ['X']],
        ],
        'variants' => [
            ['sku' => 'WIZ-SHIRT-X', 'price' => 100, 'stock' => 5, 'options' => ['Size' => 'X']],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'WIZ-SHIRT')->firstOrFail());
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    expect($variant->attributeValues)->toHaveCount(1)
        ->and($variant->attributeValues->first()->value)->toBe('X')
        ->and($variant->attributeValues->first()->attribute->name)->toBe('Size')
        ->and((int) Stock::query()->where('variant_id', $variant->id)->value('quantity'))->toBe(5);
});

it('reproduces and fixes the reported bug: adding a new option value and regenerating keeps the original value and shows both on reopen', function (): void {
    [$owner, $store] = wizardPersistenceWorkspace();

    $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'Wizard Hoodie', 'sku' => 'WIZ-HOODIE', 'type' => 'variable', 'price' => 200,
        'options' => [
            ['name' => 'Color', 'values' => ['Black']],
        ],
        'variants' => [
            ['sku' => 'WIZ-HOODIE-BLK', 'price' => 200, 'stock' => 3, 'options' => ['Color' => 'Black']],
        ],
    ])->assertSessionHasNoErrors();

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'WIZ-HOODIE')->firstOrFail());
    $blackVariant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    // Add Size (a brand-new option) and regenerate — this is exactly the
    // "add Size with X, XL, regenerate" scenario from the bug report.
    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Wizard Hoodie', 'sku' => 'WIZ-HOODIE', 'type' => 'variable', 'price' => 200, 'status' => 'active',
        'options' => [
            ['name' => 'Color', 'values' => ['Black']],
            ['name' => 'Size', 'values' => ['X', 'XL']],
        ],
        'variants' => [
            ['id' => $blackVariant->id, 'sku' => 'WIZ-HOODIE-BLK-X', 'price' => 200, 'options' => ['Color' => 'Black', 'Size' => 'X']],
            ['sku' => 'WIZ-HOODIE-BLK-XL', 'price' => 200, 'options' => ['Color' => 'Black', 'Size' => 'XL']],
        ],
    ])->assertSessionHasNoErrors();

    $product = Product::withoutTenancy(fn () => Product::query()->find($product->id));

    expect(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->count()))->toBe(2);

    $reloadedBlackX = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($blackVariant->id));
    expect($reloadedBlackX)->not->toBeNull()
        ->and($reloadedBlackX->attributeValues->pluck('value')->sort()->values()->all())->toBe(['Black', 'X']);

    // Reopen the edit page — the previously entered "Black" value must not
    // have disappeared, and the new "Size" option/values must be present.
    $this->actingAs($owner)->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Products/Edit')
            ->where('product.options.0.name', 'Color')
            ->where('product.options.0.values', ['Black'])
            ->where('product.options.1.name', 'Size')
            ->has('product.variants', 2));
});

it('does not overwrite an existing variant stock quantity via an unvalidated field in the update payload', function (): void {
    [$owner, $store] = wizardPersistenceWorkspace();

    $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'Wizard Cap', 'sku' => 'WIZ-CAP', 'type' => 'variable', 'price' => 50,
        'options' => [['name' => 'Size', 'values' => ['One Size']]],
        'variants' => [['sku' => 'WIZ-CAP-OS', 'price' => 50, 'stock' => 8, 'options' => ['Size' => 'One Size']]],
    ])->assertSessionHasNoErrors();

    $product = Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'WIZ-CAP')->firstOrFail());
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $product->id)->firstOrFail());

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Wizard Cap', 'sku' => 'WIZ-CAP', 'type' => 'variable', 'price' => 50, 'status' => 'active',
        'options' => [['name' => 'Size', 'values' => ['One Size']]],
        'variants' => [['id' => $variant->id, 'sku' => 'WIZ-CAP-OS', 'price' => 50, 'stock' => 999, 'qty' => 999, 'options' => ['Size' => 'One Size']]],
    ])->assertSessionHasNoErrors();

    expect((int) Stock::query()->where('variant_id', $variant->id)->value('quantity'))->toBe(8);
});

it('does not let a variant id from another store product be hijacked through the update payload', function (): void {
    [$ownerA, $storeA] = wizardPersistenceWorkspace('Wizard Store A');
    [$ownerB, $storeB] = wizardPersistenceWorkspace('Wizard Store B');

    // Product A defines both X and Y but only ever generates a variant for X,
    // so "Size: Y" has no existing combo match — the only way to resolve the
    // crafted id below is through the id-hint path, which must re-verify
    // ownership through productA's own relation before trusting it.
    $this->actingAs($ownerA)->post('/dashboard/products', [
        'name' => 'A Product', 'sku' => 'WIZ-A', 'type' => 'variable', 'price' => 10,
        'options' => [['name' => 'Size', 'values' => ['X', 'Y']]],
        'variants' => [['sku' => 'WIZ-A-X', 'price' => 10, 'options' => ['Size' => 'X']]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($ownerB)->post('/dashboard/products', [
        'name' => 'B Product', 'sku' => 'WIZ-B', 'type' => 'variable', 'price' => 20,
        'options' => [['name' => 'Size', 'values' => ['X']]],
        'variants' => [['sku' => 'WIZ-B-X', 'price' => 20, 'options' => ['Size' => 'X']]],
    ])->assertSessionHasNoErrors();

    $productA = Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeA->id)->where('sku', 'WIZ-A')->firstOrFail());
    $productB = Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeB->id)->where('sku', 'WIZ-B')->firstOrFail());
    $variantB = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $productB->id)->firstOrFail());

    $this->actingAs($ownerA)->patch("/dashboard/products/{$productA->id}", [
        'name' => 'A Product', 'sku' => 'WIZ-A', 'type' => 'variable', 'price' => 10, 'status' => 'active',
        'options' => [['name' => 'Size', 'values' => ['X', 'Y']]],
        'variants' => [
            ['sku' => 'WIZ-A-X', 'price' => 10, 'options' => ['Size' => 'X']],
            ['id' => $variantB->id, 'sku' => 'HACKED', 'price' => 1, 'options' => ['Size' => 'Y']],
        ],
    ])->assertSessionHasNoErrors();

    $variantB = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($variantB->id));

    expect($variantB->sku)->toBe('WIZ-B-X')
        ->and((float) $variantB->price)->toBe(20.0)
        ->and($variantB->product_id)->toBe($productB->id)
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $productA->id)->count()))->toBe(2)
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::query()->where('product_id', $productA->id)->whereKey($variantB->id)->exists()))->toBeFalse();
});
