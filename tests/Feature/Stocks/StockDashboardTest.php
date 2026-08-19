<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Multi-warehouse Stock dashboard: totals, breakdown, filter, scoped adjust
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);

    $this->primary = $this->store->getPrimaryWarehouse();
    $this->second  = Warehouse::create([
        'user_id' => $this->owner->id, 'name' => 'Depot B', 'type' => Warehouse::TYPE_STANDARD, 'is_active' => true,
    ]);
    $this->store->warehouses()->attach($this->second->id, ['is_primary' => false, 'priority' => 2]);

    // Alpha: simple, split 30 (primary) + 20 (Depot B) = 50.
    $this->alpha = Product::create([
        'store_id' => $this->store->id, 'name' => 'Alpha Split', 'sku' => 'ALPHA',
        'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]);
    Stock::create(['product_id' => $this->alpha->id, 'warehouse_id' => $this->primary->id, 'quantity' => 30]);
    Stock::create(['product_id' => $this->alpha->id, 'warehouse_id' => $this->second->id,  'quantity' => 20]);

    // Bravo: simple, primary only (10).
    $this->bravo = Product::create([
        'store_id' => $this->store->id, 'name' => 'Bravo Primary', 'sku' => 'BRAVO',
        'type' => 'simple', 'status' => 'active', 'price' => 5,
    ]);
    Stock::create(['product_id' => $this->bravo->id, 'warehouse_id' => $this->primary->id, 'quantity' => 10]);

    // Charlie: variable, one variant split 15 (primary) + 5 (Depot B) = 20.
    $this->charlie = Product::create([
        'store_id' => $this->store->id, 'name' => 'Charlie Variable', 'sku' => 'CHARLIE',
        'type' => 'variable', 'status' => 'active', 'price' => 20,
    ]);
    $this->variant = ProductVariant::create([
        'product_id' => $this->charlie->id, 'name' => 'Red', 'sku' => 'CHARLIE-RED', 'price' => 20,
    ]);
    Stock::create(['product_id' => $this->charlie->id, 'variant_id' => $this->variant->id, 'warehouse_id' => $this->primary->id, 'quantity' => 15]);
    Stock::create(['product_id' => $this->charlie->id, 'variant_id' => $this->variant->id, 'warehouse_id' => $this->second->id,  'quantity' => 5]);
});

it('shows combined totals and a per-warehouse breakdown in the global view', function () {
    $this->actingAs($this->owner)
        ->get('/dashboard/stock')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Stock')
            ->where('primaryWarehouseId', $this->primary->id)
            ->has('warehouses', 2)
            ->where('stats.total_products', 3)
            // Alpha (first alphabetically): total 50 across 2 warehouses.
            ->where('products.data.0.total_stock', 50)
            ->where('products.data.0.warehouse_count', 2)
            ->has('products.data.0.breakdown', 2)
            // Charlie: variant-aware total 20, with per-variant breakdown.
            ->where('products.data.2.total_stock', 20)
            ->has('products.data.2.variants.0.breakdown', 2)
            ->where('products.data.2.variants.0.stock', 20)
        );
});

it('filters products and stock to a selected warehouse', function () {
    $this->actingAs($this->owner)
        ->get('/dashboard/stock?warehouse=' . $this->second->id)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Stock')
            // Bravo is primary-only, so only Alpha + Charlie remain.
            ->has('products.data', 2)
            ->where('stats.total_products', 2)
            ->where('products.data.0.total_stock', 20)   // Alpha in Depot B
            ->has('products.data.0.breakdown', 1)
            ->where('products.data.1.total_stock', 5)    // Charlie variant in Depot B
        );
});

it('adjusts stock in the requested warehouse, leaving others untouched', function () {
    $this->actingAs($this->owner)
        ->post("/dashboard/stock/{$this->alpha->id}/adjust", [
            'mode'         => 'delta',
            'reason'       => 'adjustment',
            'warehouse_id' => $this->second->id,
            'adjustments'  => [['variant_id' => null, 'quantity_change' => 5]],
        ])
        ->assertSessionHas('success');

    expect((int) Stock::where('product_id', $this->alpha->id)->where('warehouse_id', $this->second->id)->value('quantity'))->toBe(25);
    expect((int) Stock::where('product_id', $this->alpha->id)->where('warehouse_id', $this->primary->id)->value('quantity'))->toBe(30);
});

it('defaults adjustments to the primary warehouse when none is given', function () {
    $this->actingAs($this->owner)
        ->post("/dashboard/stock/{$this->bravo->id}/adjust", [
            'mode'        => 'delta',
            'reason'      => 'adjustment',
            'adjustments' => [['variant_id' => null, 'quantity_change' => 5]],
        ])
        ->assertSessionHas('success');

    expect((int) Stock::where('product_id', $this->bravo->id)->where('warehouse_id', $this->primary->id)->value('quantity'))->toBe(15);
});
