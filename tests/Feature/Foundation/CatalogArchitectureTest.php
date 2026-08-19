<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariantChannelListing;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\Sync\ProductPushService;
use App\Services\Sync\ProductSyncService;
use App\Services\Sync\VariantPushService;
use App\Support\OrderLineItems;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;

function catalogProduct(Store $store, ?string $sku, string $name = 'Catalog product'): Product
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

function catalogConnection(Store $store, string $platform): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => $platform,
        'status' => 'active',
    ]));
}

it('scopes product SKU uniqueness to a store catalog instead of the whole SaaS', function (): void {
    $user = User::factory()->create();
    $storeA = Store::factory()->create(['user_id' => $user->id]);
    $storeB = Store::factory()->create(['user_id' => $user->id]);

    $a = catalogProduct($storeA, 'SHARED-SKU');
    $b = catalogProduct($storeB, 'SHARED-SKU');

    expect($a->sku)->toBe('SHARED-SKU')
        ->and($b->sku)->toBe('SHARED-SKU');

    expect(fn () => catalogProduct($storeA, 'SHARED-SKU', 'Duplicate in A'))
        ->toThrow(QueryException::class);
});

it('stores one canonical product with a different remote id for each channel', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $woo = catalogConnection($store, 'woocommerce');
    $shopify = catalogConnection($store, 'shopify');
    $product = catalogProduct($store, 'MULTI-001');

    ProductChannelListing::create([
        'product_id' => $product->id,
        'platform_connection_id' => $woo->id,
        'external_product_id' => '123',
    ]);
    ProductChannelListing::create([
        'product_id' => $product->id,
        'platform_connection_id' => $shopify->id,
        'external_product_id' => '987654',
    ]);

    expect($product->fresh()->channelListings()->count())->toBe(2)
        ->and($product->externalIdForConnection($woo))->toBe('123')
        ->and($product->externalIdForConnection($shopify))->toBe('987654')
        ->and(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1);
});

it('rejects a channel listing that connects a product to another stores connection', function (): void {
    $user = User::factory()->create();
    $storeA = Store::factory()->create(['user_id' => $user->id]);
    $storeB = Store::factory()->create(['user_id' => $user->id]);
    $product = catalogProduct($storeA, 'SAFE-001');
    $foreignConnection = catalogConnection($storeB, 'woocommerce');

    expect(fn () => ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id,
        'platform_connection_id' => $foreignConnection->id,
        'external_product_id' => 'foreign-1',
    ])))->toThrow(\LogicException::class);
});

it('links a second platform by SKU without duplicating or overwriting the canonical product', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $woo = catalogConnection($store, 'woocommerce');
    $shopify = catalogConnection($store, 'shopify');
    $sync = app(ProductSyncService::class);

    expect($sync->saveProduct([
        'external_id' => 'woo-100',
        'name' => 'Canonical name',
        'sku' => 'MERGE-001',
        'type' => 'simple',
        'status' => 'active',
        'price' => 100,
        'stock' => 5,
    ], $store, 'woocommerce', $woo))->toBe('created');

    expect($sync->saveProduct([
        'external_id' => 'shop-900',
        'name' => 'Other channel name',
        'sku' => 'MERGE-001',
        'type' => 'simple',
        'status' => 'active',
        'price' => 999,
        'stock' => 99,
    ], $store, 'shopify', $shopify))->toBe('updated');

    $product = Product::withoutTenancy(fn () => Product::query()
        ->where('store_id', $store->id)
        ->where('sku', 'MERGE-001')
        ->firstOrFail());

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->count()))->toBe(1)
        ->and($product->name)->toBe('Canonical name')
        ->and((float) $product->price)->toBe(100.0)
        ->and($product->channelListings()->count())->toBe(2)
        ->and($product->externalIdForConnection($woo))->toBe('woo-100')
        ->and($product->externalIdForConnection($shopify))->toBe('shop-900')
        ->and((int) Stock::withoutTenancy(fn () => Stock::query()->where('product_id', $product->id)->value('quantity')))->toBe(5);
});

it('maps the same canonical variant to different ids on two channels', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $woo = catalogConnection($store, 'woocommerce');
    $shopify = catalogConnection($store, 'shopify');
    $sync = app(ProductSyncService::class);

    $sync->saveProduct([
        'external_id' => 'woo-parent',
        'name' => 'Variable product',
        'sku' => 'VAR-PARENT',
        'type' => 'variable',
        'status' => 'active',
        'price' => 0,
        'variants' => [[
            'external_id' => 'woo-red',
            'name' => 'Red',
            'sku' => 'VAR-RED',
            'price' => 120,
            'stock' => 4,
            'attributes' => ['Color' => 'Red'],
        ]],
    ], $store, 'woocommerce', $woo);

    $sync->saveProduct([
        'external_id' => 'shop-parent',
        'name' => 'Should not overwrite',
        'sku' => 'VAR-PARENT',
        'type' => 'variable',
        'status' => 'active',
        'price' => 0,
        'variants' => [[
            'external_id' => 'shop-red',
            'name' => 'Rouge',
            'sku' => 'VAR-RED',
            'price' => 999,
            'stock' => 88,
            'attributes' => ['Color' => 'Rouge'],
        ]],
    ], $store, 'shopify', $shopify);

    $product = Product::withoutTenancy(fn () => Product::query()
        ->where('store_id', $store->id)
        ->where('sku', 'VAR-PARENT')
        ->with('variants')
        ->firstOrFail());
    $variant = $product->variants->sole();

    expect($product->channelListings()->count())->toBe(2)
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->channelListings()->count())->toBe(2)
        ->and($variant->externalIdForConnection($woo))->toBe('woo-red')
        ->and($variant->externalIdForConnection($shopify))->toBe('shop-red')
        ->and((float) $variant->price)->toBe(120.0)
        ->and((int) Stock::withoutTenancy(fn () => Stock::query()->where('variant_id', $variant->id)->value('quantity')))->toBe(4)
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->count()))->toBe(2);
});

it('tenant scopes channel listings through their canonical product', function (): void {
    $user = User::factory()->create();
    $storeA = Store::factory()->create(['user_id' => $user->id]);
    $storeB = Store::factory()->create(['user_id' => $user->id]);
    $connectionA = catalogConnection($storeA, 'woocommerce');
    $connectionB = catalogConnection($storeB, 'shopify');
    $productA = catalogProduct($storeA, 'LIST-A');
    $productB = catalogProduct($storeB, 'LIST-B');

    ProductChannelListing::withoutTenancy(function () use ($productA, $productB, $connectionA, $connectionB): void {
        ProductChannelListing::create([
            'product_id' => $productA->id,
            'platform_connection_id' => $connectionA->id,
            'external_product_id' => 'A-1',
        ]);
        ProductChannelListing::create([
            'product_id' => $productB->id,
            'platform_connection_id' => $connectionB->id,
            'external_product_id' => 'B-1',
        ]);
    });

    app(TenantContext::class)->set($storeA->id, $storeA->organization_id);

    expect(ProductChannelListing::query()->pluck('external_product_id')->all())->toBe(['A-1']);
});

it('maps online order item ids through the orders exact platform connection', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $woo = catalogConnection($store, 'woocommerce');
    $shopify = catalogConnection($store, 'shopify');
    $wooProduct = catalogProduct($store, 'ORDER-WOO', 'Woo product');
    $shopProduct = catalogProduct($store, 'ORDER-SHOP', 'Shopify product');

    // Same remote id on two different platforms is perfectly valid.
    ProductChannelListing::create([
        'product_id' => $wooProduct->id,
        'platform_connection_id' => $woo->id,
        'external_product_id' => '100',
    ]);
    ProductChannelListing::create([
        'product_id' => $shopProduct->id,
        'platform_connection_id' => $shopify->id,
        'external_product_id' => '100',
    ]);

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'platform_connection_id' => $woo->id,
        'platform_order_id' => 'woo-order-1',
        'items' => [[
            'product_id' => '100',
            'name' => 'Woo item',
            'quantity' => 2,
            'price' => 50,
        ]],
    ]);

    $line = OrderLineItems::for($order)[0];

    expect($line['product_id'])->toBe($wooProduct->id)
        ->and($line['product_id'])->not->toBe($shopProduct->id);
});


it('does not let a second channels remote variant id collision overwrite the canonical variant', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $woo = catalogConnection($store, 'woocommerce');
    $shopify = catalogConnection($store, 'shopify');
    $sync = app(ProductSyncService::class);

    $sync->saveProduct([
        'external_id' => 'woo-parent-collision',
        'name' => 'Collision product',
        'sku' => 'COLLISION-PARENT',
        'type' => 'variable',
        'status' => 'active',
        'price' => 0,
        'variants' => [[
            'external_id' => 'REMOTE-SAME-ID',
            'name' => 'Canonical variant',
            'sku' => 'COLLISION-VARIANT',
            'price' => 75,
            'stock' => 3,
            'attributes' => ['Color' => 'Blue'],
        ]],
    ], $store, 'woocommerce', $woo);

    $sync->saveProduct([
        'external_id' => 'shop-parent-collision',
        'name' => 'Other channel',
        'sku' => 'COLLISION-PARENT',
        'type' => 'variable',
        'status' => 'active',
        'price' => 0,
        'variants' => [[
            // Remote ids are scoped to a channel; equality here is coincidence.
            'external_id' => 'REMOTE-SAME-ID',
            'name' => 'Must not overwrite',
            'sku' => 'COLLISION-VARIANT',
            'price' => 999,
            'stock' => 99,
            'attributes' => ['Color' => 'Red'],
        ]],
    ], $store, 'shopify', $shopify);

    $product = Product::withoutTenancy(fn () => Product::query()
        ->where('store_id', $store->id)
        ->where('sku', 'COLLISION-PARENT')
        ->firstOrFail());
    $variant = $product->variants()->sole();

    expect((float) $variant->price)->toBe(75.0)
        ->and((int) Stock::withoutTenancy(fn () => Stock::query()->where('variant_id', $variant->id)->value('quantity')))->toBe(3)
        ->and($variant->externalIdForConnection($woo))->toBe('REMOTE-SAME-ID')
        ->and($variant->externalIdForConnection($shopify))->toBe('REMOTE-SAME-ID')
        ->and($variant->channelListings()->count())->toBe(2);
});


it('self heals legacy product and variant mappings instead of creating remote duplicates', function (): void {
    $user = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $woo = catalogConnection($store, 'woocommerce');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id,
        'name' => 'Legacy mapped product',
        'sku' => 'LEGACY-MAP',
        'type' => 'variable',
        'status' => 'active',
        'price' => 0,
        'platform' => 'woocommerce',
        'external_id' => 'legacy-product-id',
    ]));

    $variant = $product->variants()->create([
        'name' => 'Legacy variant',
        'sku' => 'LEGACY-VARIANT',
        'price' => 25,
        'external_id' => 'legacy-variant-id',
    ]);

    expect($product->listingForConnection($woo))->toBeNull()
        ->and($variant->listingForConnection($woo))->toBeNull();

    $productResult = app(ProductPushService::class)->createProduct($product, 'woocommerce');
    $variantResult = app(VariantPushService::class)->createVariant($variant, 'woocommerce');

    expect($productResult[0]['success'])->toBeTrue()
        ->and($variantResult[0]['success'])->toBeTrue()
        ->and($product->fresh()->listingForConnection($woo)?->external_product_id)->toBe('legacy-product-id')
        ->and($variant->fresh()->listingForConnection($woo)?->external_variant_id)->toBe('legacy-variant-id');
});
