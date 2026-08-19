<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\TenantContext;

function tenantTestProduct(Store $store, string $sku, string $name): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => $sku,
        'type' => 'simple',
        'status' => 'active',
        'price' => 100,
    ]));
}

it('scopes direct store-owned models to the active store', function (): void {
    $user = User::factory()->create();
    $storeA = Store::factory()->create(['user_id' => $user->id]);
    $storeB = Store::factory()->create(['user_id' => $user->id]);

    $productA = tenantTestProduct($storeA, 'TENANT-A-001', 'A product');
    tenantTestProduct($storeB, 'TENANT-B-001', 'B product');

    PlatformConnection::withoutTenancy(function () use ($storeA, $storeB): void {
        PlatformConnection::create([
            'store_id' => $storeA->id,
            'platform' => 'woocommerce',
            'status' => 'active',
        ]);
        PlatformConnection::create([
            'store_id' => $storeB->id,
            'platform' => 'shopify',
            'status' => 'active',
        ]);
    });

    app(TenantContext::class)->set($storeA->id, $storeA->organization_id);

    expect(Product::query()->pluck('id')->all())->toBe([$productA->id])
        ->and(PlatformConnection::query()->pluck('store_id')->unique()->values()->all())
        ->toBe([$storeA->id]);

    expect(Product::withoutTenancy(fn () => Product::query()->count()))->toBe(2)
        ->and(PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->count()))->toBe(2);
});

it('auto-fills store_id for direct tenant models', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);

    app(TenantContext::class)->set($store->id, $store->organization_id);

    $product = Product::create([
        'name' => 'Context product',
        'sku' => 'TENANT-AUTOFILL-001',
        'type' => 'simple',
        'status' => 'active',
        'price' => 10,
    ]);

    expect($product->store_id)->toBe($store->id);
});

it('scopes warehouses and inventory through their ownership relationships', function (): void {
    $user = User::factory()->create();
    $storeA = Store::factory()->create(['user_id' => $user->id]);
    $storeB = Store::factory()->create(['user_id' => $user->id]);

    $warehouseA = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id,
        'name' => 'Warehouse A',
        'type' => Warehouse::TYPE_STANDARD,
    ]));
    $warehouseB = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id,
        'name' => 'Warehouse B',
        'type' => Warehouse::TYPE_STANDARD,
    ]));

    $storeA->warehouses()->attach($warehouseA->id, ['is_primary' => true, 'priority' => 1]);
    $storeB->warehouses()->attach($warehouseB->id, ['is_primary' => true, 'priority' => 1]);

    $productA = tenantTestProduct($storeA, 'STOCK-A-001', 'Stock A');
    $productB = tenantTestProduct($storeB, 'STOCK-B-001', 'Stock B');

    Stock::withoutTenancy(function () use ($productA, $productB, $warehouseA, $warehouseB): void {
        Stock::create([
            'product_id' => $productA->id,
            'warehouse_id' => $warehouseA->id,
            'quantity' => 5,
        ]);
        Stock::create([
            'product_id' => $productB->id,
            'warehouse_id' => $warehouseB->id,
            'quantity' => 9,
        ]);
    });

    app(TenantContext::class)->set($storeA->id, $storeA->organization_id);

    expect(Warehouse::query()->pluck('id')->all())->toBe([$warehouseA->id])
        ->and(Stock::query()->pluck('quantity')->all())->toBe([5]);
});

it('scopes product children and rejects cross-product attribute values', function (): void {
    $user = User::factory()->create();
    $storeA = Store::factory()->create(['user_id' => $user->id]);
    $storeB = Store::factory()->create(['user_id' => $user->id]);

    $productA = tenantTestProduct($storeA, 'VAR-A-001', 'Variable A');
    $productB = tenantTestProduct($storeB, 'VAR-B-001', 'Variable B');

    $variantA = ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $productA->id,
        'name' => 'A / Red',
        'sku' => 'VARIANT-A-RED',
        'price' => 100,
    ]));
    ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $productB->id,
        'name' => 'B / Blue',
        'sku' => 'VARIANT-B-BLUE',
        'price' => 100,
    ]));

    $attributeA = ProductAttribute::withoutTenancy(fn () => ProductAttribute::create([
        'product_id' => $productA->id,
        'name' => 'Color',
        'slug' => 'color',
    ]));
    $attributeB = ProductAttribute::withoutTenancy(fn () => ProductAttribute::create([
        'product_id' => $productB->id,
        'name' => 'Color',
        'slug' => 'color',
    ]));

    $valueA = ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::create([
        'attribute_id' => $attributeA->id,
        'value' => 'Red',
        'slug' => 'red',
    ]));
    $valueB = ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::create([
        'attribute_id' => $attributeB->id,
        'value' => 'Blue',
        'slug' => 'blue',
    ]));

    app(TenantContext::class)->set($storeA->id, $storeA->organization_id);

    expect(ProductVariant::query()->pluck('id')->all())->toBe([$variantA->id])
        ->and(ProductAttributeValue::query()->pluck('id')->all())->toBe([$valueA->id]);

    expect(fn () => $variantA->syncAttributeValues([$valueB->id]))
        ->toThrow(InvalidArgumentException::class);
});
