<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Connectors\YouCanConnector;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\SyncLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductPushService
{
    /**
     * Create a brand-new product on every connected platform and save the returned
     * external_id (+ platform slug) back to the product row (first success wins).
     *
     * @return array<int, array{success: bool, platform: string, external_id: string, message: string}>
     */
    public function createProduct(Product $product, ?string $platform = null): array
    {
        if ($product->isVariable()) {
            return $this->createVariableProduct($product, $platform);
        }

        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            try {
                $connector = $this->makeConnector($connection);
                $result    = $connector->createProduct($product);

                // Persist the external_id from the first successful platform
                if ($result['success'] && !empty($result['external_id']) && empty($product->external_id)) {
                    $product->update([
                        'external_id' => $result['external_id'],
                        'platform'    => $connection->platform,
                    ]);
                    $product->refresh();
                }

                $this->log($connection, 'product', $product->id, $result['success'], $result['external_id'] ?? null, $result['error'] ?? null);
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, null, $e->getMessage());
                $results[] = ['success' => false, 'platform' => $connection->platform, 'external_id' => '', 'message' => $e->getMessage(), 'error' => $e->getMessage()];
                Log::warning('ProductPushService::createProduct failed', ['product' => $product->id, 'platform' => $connection->platform, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Create a variable product with all its variants on every connected platform in one shot.
     * Product and variant external_ids are persisted (first-platform-wins for the model fields;
     * per-platform IDs are always available via SyncLog).
     *
     * @return array<int, array{success: bool, platform: string, external_id: string, message: string, variant_ids: array<string,string>}>
     */
    public function createVariableProduct(Product $product, ?string $platform = null): array
    {
        if (!$product->variants()->exists()) {
            return [[
                'success'     => false,
                'platform'    => $platform ?? 'all',
                'external_id' => '',
                'variant_ids' => [],
                'message'     => 'Variable product has no variants — add variants before pushing',
                'error'       => 'no_variants',
            ]];
        }

        $productData = $this->gatherVariableProductData($product);
        $results     = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            try {
                $connector = $this->makeConnector($connection);
                $result    = $connector->createVariableProduct($productData);

                if ($result['success']) {
                    if (!empty($result['external_id']) && empty($product->external_id)) {
                        $product->update([
                            'external_id' => $result['external_id'],
                            'platform'    => $connection->platform,
                        ]);
                        $product->refresh();
                    }

                    foreach ($result['variant_ids'] ?? [] as $localId => $platformVariantId) {
                        if (empty($platformVariantId)) {
                            continue;
                        }

                        $variant = ProductVariant::find($localId);

                        if ($variant && empty($variant->external_id)) {
                            $variant->update(['external_id' => $platformVariantId]);
                        }

                        $this->log($connection, 'variant_create', $localId, true, $platformVariantId, null);
                    }
                }

                $this->log($connection, 'product', $product->id, $result['success'], $result['external_id'] ?? null, $result['error'] ?? null);
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, null, $e->getMessage());
                $results[] = [
                    'success'     => false,
                    'platform'    => $connection->platform,
                    'external_id' => '',
                    'variant_ids' => [],
                    'message'     => $e->getMessage(),
                    'error'       => $e->getMessage(),
                ];
                Log::warning('ProductPushService::createVariableProduct failed', [
                    'product'  => $product->id,
                    'platform' => $connection->platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Collect all product + attribute + variant data needed for a full variable-product push.
     *
     * @return array{type: string, name: string, sku: string, price: float, description: string, status: string, attributes: array<string, list<string>>, variants: list<array{local_id: string, name: string, sku: string, price: float, attributes: array<string,mixed>, stock: int}>}
     */
    private function gatherVariableProductData(Product $product): array
    {
        $warehouse    = $product->store->getPrimaryWarehouse();
        $attributeMap = [];

        $variantData = $product->variants()->get()->map(function (ProductVariant $variant) use ($product, $warehouse, &$attributeMap): array {
            $attrs = $variant->getAttribute('attributes') ?? [];

            foreach ($attrs as $name => $value) {
                $values = is_array($value) ? $value : [(string) $value];

                foreach ($values as $v) {
                    if ($v !== '') {
                        $attributeMap[$name][] = $v;
                    }
                }
            }

            $qty = $warehouse
                ? (int) Stock::where('product_id', $product->id)
                    ->where('variant_id', $variant->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('quantity')
                : $variant->getTotalStock();

            return [
                'local_id'   => $variant->id,
                'name'       => $variant->getDisplayName(),
                'sku'        => $variant->sku ?? '',
                'price'      => (float) $variant->price,
                'attributes' => $attrs,
                'stock'      => $qty,
            ];
        })->all();

        foreach ($attributeMap as $name => $values) {
            $attributeMap[$name] = array_values(array_unique($values));
        }

        return [
            'type'        => 'variable',
            'name'        => $product->name,
            'sku'         => $product->sku ?? '',
            'price'       => (float) $product->price,
            'description' => $product->description ?? '',
            'status'      => $product->status === 'active' ? 'active' : 'draft',
            'attributes'  => $attributeMap,
            'variants'    => $variantData,
        ];
    }

    /**
     * Push a product's field changes (name, price, status…) to a single platform.
     *
     * @return array{success: bool, message: string, error: ?string}
     */
    public function pushProduct(Product $product, ?string $platform = null): array
    {
        $results          = [];
        $effectivePlatform = $platform ?? $product->platform ?? null;

        foreach ($this->getConnections($product->store, $effectivePlatform) as $connection) {
            try {
                $connector = $this->makeConnector($connection);
                $result    = $connector->pushProduct($product);

                $this->log($connection, 'product', $product->id, $result['success'], $product->external_id, $result['error'] ?? null);
                $results[] = $result;
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, $product->external_id, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()];
                Log::warning('ProductPushService::pushProduct failed', ['product' => $product->id, 'platform' => $connection->platform, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Push stock quantity for a product to one or all platforms.
     * For variable products, delegates to per-variant stock push.
     *
     * @return array{success: bool, message: string, platform?: string}
     */
    public function pushStock(Product $product, ?string $platform = null): array
    {
        $results           = [];
        $effectivePlatform = $platform ?? $product->platform ?? null;

        foreach ($this->getConnections($product->store, $effectivePlatform) as $connection) {
            if (empty($product->external_id)) {
                $results[] = ['success' => false, 'message' => 'Product not linked to platform', 'platform' => $connection->platform];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $warehouse = $product->store->getPrimaryWarehouse();

                if ($product->isVariable()) {
                    $result = $this->pushVariableStock($connector, $product, $connection, $warehouse);
                } else {
                    $qty = $warehouse
                        ? (int) Stock::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity')
                        : $product->getTotalStock();

                    $result = $connector->pushStock($product, $qty);
                    $this->log($connection, 'stock', $product->id, $result['success'], $product->external_id, $result['error'] ?? null);
                }

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'stock', $product->id, false, $product->external_id, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform];
                Log::warning('ProductPushService::pushStock failed', ['product' => $product->id, 'platform' => $connection->platform, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Push a single variant's stock to all platforms.
     *
     * @return array<int, array{success: bool, message: string, platform: string}>
     */
    public function pushVariantStock(ProductVariant $variant, ?string $platform = null): array
    {
        $product = $variant->product;
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            if (empty($variant->external_id)) {
                $results[] = ['success' => false, 'message' => 'Variant not linked to platform', 'platform' => $connection->platform];
                continue;
            }

            try {
                $warehouse = $product->store->getPrimaryWarehouse();
                $qty       = $warehouse
                    ? (int) Stock::where('product_id', $product->id)->where('variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('quantity')
                    : 0;

                $connector = $this->makeConnector($connection);
                $result    = $this->pushVariantStockViaConnector($connector, $product, $variant, $qty, $connection);

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'stock', $variant->id, false, $variant->external_id, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform];
                Log::warning('ProductPushService::pushVariantStock failed', ['variant' => $variant->id, 'platform' => $connection->platform, 'error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Push all changes for a product (fields + stock) to every active connected platform.
     *
     * @return array{product: array, stock: array}
     */
    public function pushAllPlatforms(Product $product): array
    {
        return [
            'product' => $this->pushProduct($product),
            'stock'   => $this->pushStock($product),
        ];
    }

    /**
     * @deprecated Use pushAllPlatforms() — kept for backwards compatibility.
     */
    public function pushAllChanges(Product $product): array
    {
        $productResults = $this->pushProduct($product);
        $stockResults   = $this->pushStock($product);

        $variantResults = [];
        foreach ($product->variants as $variant) {
            $variantResults[] = $this->pushVariantStock($variant);
        }

        return [
            'product'  => $productResults,
            'variants' => $variantResults,
            'stock'    => $stockResults,
        ];
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /**
     * Push stock for each variant of a variable product.
     *
     * @return array{success: bool, message: string}
     */
    private function pushVariableStock(object $connector, Product $product, PlatformConnection $connection, mixed $warehouse): array
    {
        $allOk  = true;
        $pushed = 0;

        foreach ($product->variants as $variant) {
            if (empty($variant->external_id)) {
                continue;
            }

            $qty = $warehouse
                ? (int) Stock::where('product_id', $product->id)->where('variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('quantity')
                : 0;

            $result = $this->pushVariantStockViaConnector($connector, $product, $variant, $qty, $connection);

            if (!$result['success']) {
                $allOk = false;
            } else {
                $pushed++;
            }
        }

        return [
            'success' => $allOk,
            'message' => $allOk ? "Pushed stock for {$pushed} variant(s)" : 'Some variant stock pushes failed',
        ];
    }

    /**
     * Push a single variant's quantity using the connector's platform-specific method.
     *
     * @return array{success: bool, message: string}
     */
    private function pushVariantStockViaConnector(object $connector, Product $product, ProductVariant $variant, int $qty, PlatformConnection $connection): array
    {
        $result = match (true) {
            $connector instanceof WooCommerceConnector => $this->wooVariantStock($connector, $product, $variant, $qty),
            $connector instanceof ShopifyConnector     => $this->shopifyVariantStock($connector, $variant, $qty),
            $connector instanceof YouCanConnector      => $this->youcanVariantStock($connector, $product, $variant, $qty),
            default                                    => ['success' => false, 'message' => 'Unknown connector type'],
        };

        $this->log($connection, 'stock', $variant->id, $result['success'], $variant->external_id, $result['success'] ? null : ($result['message'] ?? null));

        return $result;
    }

    /** WooCommerce: PUT /products/{parent}/variations/{variantId} */
    private function wooVariantStock(WooCommerceConnector $connector, Product $product, ProductVariant $variant, int $qty): array
    {
        try {
            $response = $connector->client()->put(
                "/products/{$product->external_id}/variations/{$variant->external_id}",
                ['stock_quantity' => $qty, 'manage_stock' => true]
            );

            return $response->successful()
                ? ['success' => true,  'message' => "WooCommerce variant stock set to {$qty}"]
                : ['success' => false, 'message' => 'WooCommerce returned ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Shopify: POST /inventory_levels/set.json for the variant's inventory_item_id */
    private function shopifyVariantStock(ShopifyConnector $connector, ProductVariant $variant, int $qty): array
    {
        try {
            // Fetch the variant to get inventory_item_id
            $variantResponse = $connector->client()->get("/variants/{$variant->external_id}.json");
            $inventoryItemId = $variantResponse->json('variant.inventory_item_id');

            if (!$inventoryItemId) {
                return ['success' => false, 'message' => 'Shopify variant has no inventory_item_id'];
            }

            $locationResponse = $connector->client()->get('/locations.json');
            $locationId       = $locationResponse->json('locations.0.id');

            if (!$locationId) {
                return ['success' => false, 'message' => 'No Shopify location found'];
            }

            $setResponse = $connector->client()->post('/inventory_levels/set.json', [
                'location_id'       => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'available'         => $qty,
            ]);

            return $setResponse->successful()
                ? ['success' => true,  'message' => "Shopify variant stock set to {$qty}"]
                : ['success' => false, 'message' => 'Shopify returned ' . $setResponse->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** YouCan: PUT /api/products/{productId}/variants/{variantId} */
    private function youcanVariantStock(YouCanConnector $connector, Product $product, ProductVariant $variant, int $qty): array
    {
        try {
            $response = $connector->client()->put(
                "/api/products/{$product->external_id}/variants/{$variant->external_id}",
                ['quantity' => $qty]
            );

            return $response->successful()
                ? ['success' => true,  'message' => "YouCan variant stock set to {$qty}"]
                : ['success' => false, 'message' => 'YouCan returned ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Return active platform connections for a store, optionally filtered to one platform. */
    private function getConnections(Store $store, ?string $platform): Collection
    {
        return $store->connections()
            ->where('status', 'active')
            ->when($platform, fn ($q) => $q->where('platform', $platform))
            ->get();
    }

    private function makeConnector(PlatformConnection $connection): object
    {
        return match ($connection->platform) {
            'woocommerce' => new WooCommerceConnector($connection),
            'shopify'     => new ShopifyConnector($connection),
            'youcan'      => new YouCanConnector($connection),
            default       => throw new \InvalidArgumentException("Unknown platform: {$connection->platform}"),
        };
    }

    private function log(
        PlatformConnection $connection,
        string $action,
        string $entityId,
        bool $success,
        ?string $externalId,
        ?string $error
    ): void {
        try {
            SyncLog::create([
                'store_id'               => $connection->store_id,
                'platform_connection_id' => $connection->id,
                'platform'               => $connection->platform,
                'type'                   => 'push',
                'direction'              => 'push',
                'action'                 => $action,
                'entity_id'              => $entityId,
                'external_id'            => $externalId,
                'status'                 => $success ? 'success' : 'failed',
                'error_message'          => $error,
                'completed_at'           => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProductPushService: failed to write SyncLog', ['error' => $e->getMessage()]);
        }
    }
}
