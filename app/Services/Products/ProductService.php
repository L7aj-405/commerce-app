<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Stock;
use Illuminate\Support\Collection;

class ProductService
{
    /**
     * Create a new product
     */
    public function create(Store $store, array $data): Product
    {
        $product = Product::create([
            'store_id' => $store->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sku' => $data['sku'],
            'type' => $data['type'] ?? 'simple',
            'status' => $data['status'] ?? 'draft',
            'price' => $data['price'] ?? 0,
            'cost' => $data['cost'] ?? 0,
            'compare_price' => $data['compare_price'] ?? null,
            'images' => $data['images'] ?? [],
            'featured_image' => $data['featured_image'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        // Create initial stock for warehouse
        if (isset($data['warehouse_id']) && isset($data['quantity'])) {
            Stock::create([
                'product_id' => $product->id,
                'warehouse_id' => $data['warehouse_id'],
                'quantity' => $data['quantity'],
                'reorder_level' => $data['reorder_level'] ?? 10,
            ]);
        }

        return $product;
    }

    /**
     * Update a product
     */
    public function update(Product $product, array $data): Product
    {
        $product->update([
            'name' => $data['name'] ?? $product->name,
            'description' => $data['description'] ?? $product->description,
            'sku' => $data['sku'] ?? $product->sku,
            'price' => $data['price'] ?? $product->price,
            'cost' => $data['cost'] ?? $product->cost,
            'compare_price' => $data['compare_price'] ?? $product->compare_price,
            'images' => $data['images'] ?? $product->images,
            'featured_image' => $data['featured_image'] ?? $product->featured_image,
            'seo_title' => $data['seo_title'] ?? $product->seo_title,
            'seo_description' => $data['seo_description'] ?? $product->seo_description,
            'metadata' => $data['metadata'] ?? $product->metadata,
            'type' => $data['type'] ?? $product->type,
            'status' => $data['status'] ?? $product->status,
        ]);

        return $product->fresh();
    }

    /**
     * Create a variant
     */
    public function createVariant(Product $product, array $data): ProductVariant
    {
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => $data['name'],
            'sku' => $data['sku'],
            'price' => $data['price'] ?? $product->price,
            'cost' => $data['cost'] ?? $product->cost,
            'compare_price' => $data['compare_price'] ?? null,
            'attributes' => $data['attributes'] ?? [],
            'images' => $data['images'] ?? null,
            'featured_image' => $data['featured_image'] ?? null,
        ]);

        // Create stock for each warehouse
        $warehouses = $product->store->warehouses;
        foreach ($warehouses as $warehouse) {
            Stock::create([
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 0,
                'reorder_level' => 10,
            ]);
        }

        return $variant;
    }

    /**
     * Update a variant
     */
    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        $variant->update([
            'name' => $data['name'] ?? $variant->name,
            'sku' => $data['sku'] ?? $variant->sku,
            'price' => $data['price'] ?? $variant->price,
            'cost' => $data['cost'] ?? $variant->cost,
            'compare_price' => $data['compare_price'] ?? $variant->compare_price,
            'attributes' => $data['attributes'] ?? $variant->attributes,
            'images' => $data['images'] ?? $variant->images,
            'featured_image' => $data['featured_image'] ?? $variant->featured_image,
        ]);

        return $variant->fresh();
    }

    /**
     * Delete a product
     */
    public function delete(Product $product): bool
    {
        return (bool) $product->delete();
    }

    /**
     * Delete a variant
     */
    public function deleteVariant(ProductVariant $variant): bool
    {
        return (bool) $variant->delete();
    }

    /**
     * Get all products for a store
     */
    public function getStoreProducts(Store $store, array $filters = []): Collection
    {
        $query = $store->products();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('sku', 'like', "%$search%");
            });
        }

        return $query->with('variants', 'stocks')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get product with all details
     */
    public function getProductDetails(Product $product)
    {
        return $product->load([
            'variants' => fn($q) => $q->with('stocks'),
            'stocks.warehouse',
        ]);
    }

    /**
     * Check if SKU exists
     */
    public function skuExists(string $sku, Store $store, ?string $excludeProductId = null): bool
    {
        $query = Product::where('store_id', $store->id)
            ->where('sku', $sku);

        if ($excludeProductId) {
            $query->where('id', '!=', $excludeProductId);
        }

        return $query->exists();
    }

    /**
     * Duplicate a product
     */
    public function duplicate(Product $product): Product
    {
        $newProduct = $product->replicate();
        $newProduct->sku = $product->sku . '-copy';
        $newProduct->status = 'draft';
        $newProduct->save();

        // Copy variants
        foreach ($product->variants as $variant) {
            $newVariant = $variant->replicate();
            $newVariant->product_id = $newProduct->id;
            $newVariant->sku = $variant->sku . '-copy';
            $newVariant->save();
        }

        // Copy stocks
        foreach ($product->stocks as $stock) {
            Stock::create([
                'product_id' => $newProduct->id,
                'variant_id' => null,
                'warehouse_id' => $stock->warehouse_id,
                'quantity' => 0,
                'reorder_level' => $stock->reorder_level,
            ]);
        }

        return $newProduct;
    }
}