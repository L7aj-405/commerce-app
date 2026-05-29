<?php

declare(strict_types=1);

namespace App\Services\Stocks;

use App\Models\Stock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\StockMovement;
use App\Enums\StockMovementType;

class StockService
{
   /**
 * Add stock to a product (or a specific variant if provided).
 */
public function addStock(
    Product $product,
    Warehouse $warehouse,
    int|string $quantity,
    ?string $notes = null,
    ?ProductVariant $variant = null
): Stock {
    $quantity = (int) $quantity;

    $stock = $this->getOrCreateStock($product, $warehouse, $variant);
    $stock->quantity += $quantity;
    $stock->save();

    StockMovement::create([
        'product_id'   => $product->id,
        'variant_id'   => $variant?->id,
        'warehouse_id' => $warehouse->id,
        'user_id'      => auth()->id(),
        'type'         => StockMovementType::In->value,
        'quantity'     => $quantity,
        'notes'        => $notes,
    ]);

    return $stock;
}

/**
 * Remove stock
 */
public function removeStock(
    Product $product,
    Warehouse $warehouse,
    int|string $quantity,
    ?string $notes = null
): Stock
{
    $quantity = (int) $quantity;
    
    $stock = $this->getOrCreateStock($product, $warehouse);
    $stock->quantity = max(0, $stock->quantity - $quantity);
    $stock->save();
    
    StockMovement::create([
        'product_id'   => $product->id,
        'warehouse_id' => $warehouse->id,
        'user_id'      => auth()->id(),
        'type'         => StockMovementType::Out->value,
        'quantity'     => -$quantity,
        'notes'        => $notes,
    ]);
    
    return $stock;
}

  

   /**
 * Adjust stock quantity to an absolute value.
 * Pass $variantId when adjusting a variant-level stock row so the query
 * targets the correct row instead of the first match for the product.
 */
public function adjustStock(
    Product $product,
    Warehouse $warehouse,
    int|string $newQuantity,
    ?string $notes = null,
    ?string $type = 'adjustment',
    ?string $variantId = null
): Stock {
    $newQuantity = (int) $newQuantity;

    $stock = Stock::where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('variant_id', $variantId)
        ->firstOrFail();

    $oldQuantity = $stock->quantity;
    $difference  = $newQuantity - $oldQuantity;

    $stock->update(['quantity' => $newQuantity]);

    StockMovement::create([
        'product_id'   => $product->id,
        'variant_id'   => $variantId,
        'warehouse_id' => $warehouse->id,
        'user_id'      => auth()->id(),
        'type'         => $type ?? StockMovementType::Adjustment->value,
        'quantity'     => $difference,
        'notes'        => $notes ?? "Stock adjusted from {$oldQuantity} to {$newQuantity}",
    ]);

    return $stock;
}

    /**
     * Get or create stock
     */
    public function getOrCreateStock(
        Product $product,
        Warehouse $warehouse,
        ?ProductVariant $variant = null
    ): Stock {
        return Stock::firstOrCreate([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'warehouse_id' => $warehouse->id,
        ], [
            'quantity' => 0,
            'reserved' => 0,
            'reorder_level' => 10,
        ]);
    }

    /**
     * Record stock movement
     */
    public function recordMovement(
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant,
        StockMovementType $type,
        int $quantity,
        ?string $reference = null,
        ?string $notes = null
    ): StockMovement {
        return StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'type' => $type,
            'quantity' => $quantity,
            'reference' => $reference,
            'notes' => $notes,
        ]);
    }

    /**
     * Get low stock products
     */
    public function getLowStockProducts(Warehouse $warehouse)
    {
        return Stock::where('warehouse_id', $warehouse->id)
            ->whereRaw('quantity <= reorder_level')
            ->with('product', 'variant')
            ->get();
    }

    /**
     * Get stock movements
     */
    public function getMovements(
        Warehouse $warehouse,
        ?Product $product = null,
        int $limit = 50
    ) {
        $query = StockMovement::where('warehouse_id', $warehouse->id);

        if ($product) {
            $query->where('product_id', $product->id);
        }

        return $query->with('product', 'variant', 'user')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get total stock across warehouses
     */
    public function getTotalStock(Product $product, ?ProductVariant $variant = null): int
    {
        $query = Stock::where('product_id', $product->id);

        if ($variant) {
            $query->where('variant_id', $variant->id);
        }

        return $query->sum('quantity');
    }

    /**
     * Get stock by warehouse
     */
    public function getStockByWarehouse(Product $product, ?ProductVariant $variant = null): array
    {
        $query = Stock::where('product_id', $product->id);

        if ($variant) {
            $query->where('variant_id', $variant->id);
        }

        return $query->with('warehouse')
            ->get()
            ->mapWithKeys(fn($stock) => [
                $stock->warehouse->id => [
                    'warehouse_name' => $stock->warehouse->name,
                    'quantity' => $stock->quantity,
                    'available' => $stock->getAvailable(),
                    'reserved' => $stock->reserved,
                ]
            ])
            ->toArray();
    }
}