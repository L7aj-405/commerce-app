<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use App\Connectors\WooCommerceConnector;
use App\Connectors\ShopifyConnector;
use App\Connectors\YouCanConnector;
use Illuminate\Support\Facades\Log;

class ProductSyncService
{
    public function syncFromPlatform(Store $store, string $platform): array
    {
        // Get connection for this store and platform
        $connection = $store->connections()->where('platform', $platform)->first();
        
        if (!$connection) {
            Log::warning("No connection found for platform", [
                'store' => $store->id,
                'platform' => $platform,
            ]);
            return ['created' => 0, 'updated' => 0, 'failed' => 0];
        }

        // Get connector based on platform
        $connector = $this->getConnector($platform, $connection);
        
        $created = 0;
        $updated = 0;
        $failed = 0;
        
        try {
            // Fetch ALL pages of products
            $page = 1;
            $perPage = 50;
            $hasMore = true;
            
            while ($hasMore) {
                Log::info("Fetching products page $page", ['store' => $store->id, 'platform' => $platform]);
                
                try {
                    $products = $connector->getProducts($page, $perPage);
                    
                    if (empty($products)) {
                        $hasMore = false;
                        break;
                    }
                    
                    Log::info("Fetched " . count($products) . " products from page $page", ['store' => $store->id]);
                    
                    foreach ($products as $platformProduct) {
                        try {
                            $result = $this->saveProduct($platformProduct, $store, $platform);

                            if ($result === 'created') {
                                $created++;
                            } elseif ($result === 'updated') {
                                $updated++;
                            }

                            // For variable products without embedded variants (WooCommerce),
                            // fetch variants from the dedicated variations endpoint.
                            if (
                                ($platformProduct['type'] ?? 'simple') === 'variable' &&
                                empty($platformProduct['variants']) &&
                                method_exists($connector, 'getProductVariants')
                            ) {
                                $this->syncVariantsForProduct(
                                    $connector,
                                    $platformProduct['external_id'],
                                    $store
                                );
                            }
                        } catch (\Exception $e) {
                            $failed++;
                            Log::warning("Failed to sync product", [
                                'sku'   => $platformProduct['sku'] ?? 'unknown',
                                'error' => $e->getMessage(),
                                'store' => $store->id,
                            ]);
                        }
                    }
                    
                    // Move to next page
                    $page++;
                    
                    // Stop if fewer products than per_page (last page)
                    if (count($products) < $perPage) {
                        $hasMore = false;
                    }
                    
                } catch (\Exception $e) {
                    Log::error("Error fetching products page $page", [
                        'error' => $e->getMessage(),
                        'store' => $store->id,
                    ]);
                    $hasMore = false;
                }
            }
            
            Log::info("Product sync completed", [
                'store' => $store->id,
                'platform' => $platform,
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
            ]);
            
        } catch (\Exception $e) {
            Log::error("Product sync failed", [
                'store' => $store->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);
        }
        
        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * Get connector instance based on platform
     */
    private function getConnector(string $platform, $connection)
    {
        return match($platform) {
            'woocommerce' => new WooCommerceConnector($connection),
            'shopify' => new ShopifyConnector($connection),
            'youcan' => new YouCanConnector($connection),
            default => throw new \Exception("Unknown platform: $platform"),
        };
    }

    /**
     * Fetch and save variants for a single variable product using the connector's
     * dedicated getProductVariants() endpoint. Handles pagination automatically.
     */
    private function syncVariantsForProduct(object $connector, string $externalId, Store $store): void
    {
        $product = Product::where('external_id', $externalId)
            ->where('store_id', $store->id)
            ->first();

        if ($product === null) {
            return;
        }

        $variantPage = 1;
        $perPage     = 100;

        while (true) {
            try {
                $variants = $connector->getProductVariants($externalId, $variantPage, $perPage);

                if (empty($variants)) {
                    break;
                }

                $this->createVariants($product, $variants);

                Log::info("Synced variant page {$variantPage} for product", [
                    'product'   => $product->id,
                    'external'  => $externalId,
                    'count'     => count($variants),
                ]);

                if (count($variants) < $perPage) {
                    break;
                }

                $variantPage++;
            } catch (\Exception $e) {
                Log::warning("Failed to fetch variants for product", [
                    'external_id' => $externalId,
                    'page'        => $variantPage,
                    'error'       => $e->getMessage(),
                ]);
                break;
            }
        }
    }

    public function saveProduct(array $platformProduct, Store $store, string $platform): string
{
    // Get or create warehouse
    $warehouse = $this->getOrCreateWarehouse($store);
    
    // Prepare product data
    $productData = [
        'store_id' => $store->id,
        'name' => $platformProduct['name'] ?? 'Unnamed',
        'description' => $platformProduct['description'] ?? '',
        'sku' => $platformProduct['sku'] ?? '',
        'type' => $platformProduct['type'] ?? 'simple',
        'price' => (float) ($platformProduct['price'] ?? 0),
        'cost' => (float) ($platformProduct['cost'] ?? 0),
        'compare_price' => (float) ($platformProduct['compare_price'] ?? 0),
        'status' => $platformProduct['status'] ?? 'draft',
        'featured_image' => $platformProduct['featured_image'] ?? null,
        'images' => $platformProduct['images'] ?? [],
        'external_id' => (string) ($platformProduct['external_id'] ?? ''),
        'platform' => $platform,
        'metadata' => $platformProduct['metadata'] ?? [],
        'synced_at' => now(),
    ];
    
    // Check if product exists by external_id
    $product = Product::where('external_id', $platformProduct['external_id'] ?? '')
        ->where('store_id', $store->id)
        ->first();
    
    if ($product) {
        // Update existing product
        $product->update($productData);
        Log::info("Updated product", ['sku' => $product->sku, 'id' => $product->id]);
        
        // Only track product-level stock for simple products
        if (!$product->isVariable()) {
            $this->updateStock($product, $warehouse, $platformProduct['stock'] ?? 0);
        }

        return 'updated';
    } else {
        // Create new product
        $product = Product::create($productData);
        Log::info("Created product", ['sku' => $product->sku, 'id' => $product->id]);

        // Only create product-level stock for simple products
        if (!$product->isVariable()) {
            $this->createStocks($product, $warehouse, $platformProduct['stock'] ?? 0);
        }

        // Sync variants if platform product has them
        if (!empty($platformProduct['variants']) && is_array($platformProduct['variants'])) {
            $this->createVariants($product, $platformProduct['variants']);
        }

        return 'created';
    }
}

/**
 * ✅ NEW: Create stock with quantity from platform
 */
public function createStocks(Product $product, Warehouse $warehouse, int $quantity = 0): void
{
    // ✅ Skip for variable products - only variants should have stock
    if ($product->isVariable()) {
        Log::info("Skipping parent stock for variable product", [
            'product_id' => $product->id,
            'reason' => 'Variable products use variant-level stock only',
        ]);
        return;
    }

    // Only create stock for SIMPLE products
    try {
        $stock = Stock::updateOrCreate(
            [
                'product_id'   => $product->id,
                'variant_id'   => null,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'quantity' => $quantity,
            ]
        );

        if ($stock->wasRecentlyCreated) {
            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id'   => $product->id,
                'user_id'      => $warehouse->user_id,
                'type'         => 'initial_sync',
                'quantity'     => $quantity,
                'notes'        => "Initial stock created from platform sync: $quantity units",
            ]);
        }
    } catch (\Exception $e) {
        Log::warning("Failed to create stock", [
            'product_id'   => $product->id,
            'warehouse_id' => $warehouse->id,
            'error'        => $e->getMessage(),
        ]);
    }
}

/**
 * ✅ NEW: Update stock quantity when product is updated
 */
public function updateStock(Product $product, Warehouse $warehouse, int $quantity): void
{
    try {
        $stock = Stock::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        if ($stock) {
            $oldQty = $stock->quantity;
            $stock->update(['quantity' => $quantity]);

            // Record movement if quantity changed
            if ($oldQty !== $quantity) {
                StockMovement::create([
                    'warehouse_id' => $warehouse->id,
                    'product_id'   => $product->id,
                    'user_id'      => $warehouse->user_id,
                    'type'         => StockMovementType::Adjustment->value,
                    'quantity'     => $quantity - $oldQty,
                    'notes'        => "Stock updated from platform sync: {$oldQty} → {$quantity}",
                ]);
            }
        }
    } catch (\Exception $e) {
        Log::warning("Failed to update stock", [
            'product_id'   => $product->id,
            'warehouse_id' => $warehouse->id,
            'error'        => $e->getMessage(),
        ]);
    }
}

public function createVariants(Product $product, array $platformVariants): void
{
    foreach ($platformVariants as $platformVariant) {
        if (empty($platformVariant)) {
            continue;
        }

        try {
            $externalId = (string) ($platformVariant['external_id'] ?? '');

            $variant = ProductVariant::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'external_id' => $externalId,
                ],
                [
                    'name'          => $platformVariant['name'] ?? 'Variant',
                    'sku'           => $platformVariant['sku'] ?? '',
                    'price'         => (float) ($platformVariant['price'] ?? 0),
                    'cost'          => (float) ($platformVariant['cost'] ?? 0),
                    'compare_price' => (float) ($platformVariant['compare_price'] ?? 0),
                    'attributes'    => is_array($platformVariant['attributes'] ?? null)
                        ? $platformVariant['attributes']
                        : [],
                ]
            );

            // Sync variant stock if platform provides it
            if (isset($platformVariant['stock']) && $platformVariant['stock'] !== null) {
                $this->syncVariantStock($variant, $platformVariant['stock']);
            }

            // Populate per-product attribute tables from the platform's attribute data
            if (!empty($platformVariant['attributes']) && is_array($platformVariant['attributes'])) {
                $this->syncVariantAttributesToPerProduct($variant, $product, $platformVariant['attributes']);
            }

            Log::debug("Synced variant", [
                'product_id'  => $product->id,
                'external_id' => $externalId,
                'sku'         => $platformVariant['sku'] ?? '',
            ]);
        } catch (\Exception $e) {
            Log::warning("Failed to sync variant", [
                'product_id'  => $product->id,
                'external_id' => $platformVariant['external_id'] ?? '',
                'error'       => $e->getMessage(),
            ]);
        }
    }
}

/**
 * ✅ NEW: Sync variant stock (for variable products)
 */
private function syncVariantStock(ProductVariant $variant, int $quantity): void
{
    try {
        $warehouse = $variant->product->store->getPrimaryWarehouse();
        
        Stock::updateOrCreate(
            [
                'product_id'   => $variant->product_id,
                'variant_id'   => $variant->id,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'quantity' => $quantity,
            ]
        );

        Log::debug("Synced variant stock", [
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);
    } catch (\Exception $e) {
        Log::warning("Failed to sync variant stock", [
            'variant_id' => $variant->id,
            'error' => $e->getMessage(),
        ]);
    }
}

    /**
     * Create per-product ProductAttribute / ProductAttributeValue rows from a synced variant's
     * attribute map (e.g. ['size' => 'L', 'color' => 'red']), then link the variant to those
     * values via the pivot table.  Idempotent — safe to call on re-sync.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncVariantAttributesToPerProduct(ProductVariant $variant, Product $product, array $attributes): void
    {
        try {
            $valueIds = [];

            foreach ($attributes as $attrName => $attrValue) {
                $attr   = ProductAttribute::findOrCreateForProduct($product->id, (string) $attrName);
                $values = is_array($attrValue) ? $attrValue : [(string) $attrValue];

                foreach ($values as $v) {
                    $v = trim((string) $v);

                    if ($v === '') {
                        continue;
                    }

                    $valueIds[] = ProductAttributeValue::findOrCreateForAttribute($attr->id, $v)->id;
                }
            }

            if (!empty($valueIds)) {
                $variant->attributeValues()->sync($valueIds);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to sync variant attributes to per-product tables', [
                'variant_id' => $variant->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function getOrCreateWarehouse(Store $store): Warehouse
    {
        $warehouse = $store->getDefaultWarehouse();
        
        if (!$warehouse) {
            $warehouse = Warehouse::create([
                'user_id' => $store->user_id,
                'name' => $store->name . ' - Default Warehouse',
                'address' => '',
                'city' => '',
                'state' => '',
                'zip' => '',
                'country' => '',
                'phone' => '',
                'is_default' => true,
            ]);
            
            $store->warehouses()->attach($warehouse->id, [
                'is_primary' => true,
                'priority' => 1,
            ]);
        }
        
        return $warehouse;
    }
}