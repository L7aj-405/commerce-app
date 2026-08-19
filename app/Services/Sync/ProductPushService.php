<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Connectors\YouCanConnector;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Stock;
use App\Models\Store;
use App\Models\SyncLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductPushService
{
    /**
     * Create a local product on every selected channel that does not already
     * have a listing. The returned remote id is stored per connection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function createProduct(Product $product, ?string $platform = null): array
    {
        if ($product->isVariable()) {
            return $this->createVariableProduct($product, $platform);
        }

        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $existingListing = $product->listingForConnection($connection);
            $existingExternalId = $existingListing?->external_product_id
                ?? $product->externalIdForConnection($connection);

            if ($existingExternalId !== null) {
                // Self-heal legacy products whose old external_id/platform mapping
                // exists but could not be backfilled when the migration ran.
                if ($existingListing === null) {
                    $this->recordProductListing($product, $connection, $existingExternalId);
                }

                $results[] = [
                    'success' => true,
                    'platform' => $connection->platform,
                    'external_id' => $existingExternalId,
                    'message' => 'Product is already linked to this channel.',
                    'error' => null,
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result = $connector->createProduct($product);

                if (($result['success'] ?? false) && ! empty($result['external_id'])) {
                    $this->recordProductListing($product, $connection, (string) $result['external_id']);
                }

                $this->log(
                    $connection,
                    'product',
                    $product->id,
                    (bool) ($result['success'] ?? false),
                    $result['external_id'] ?? null,
                    $result['error'] ?? null,
                );

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, null, $e->getMessage());
                $results[] = [
                    'success' => false,
                    'platform' => $connection->platform,
                    'external_id' => '',
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function createVariableProduct(Product $product, ?string $platform = null): array
    {
        if (! $product->variants()->exists()) {
            return [[
                'success' => false,
                'platform' => $platform ?? 'all',
                'external_id' => '',
                'variant_ids' => [],
                'message' => 'Variable product has no variants — add variants before pushing',
                'error' => 'no_variants',
            ]];
        }

        $productData = $this->gatherVariableProductData($product);
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $existingListing = $product->listingForConnection($connection);
            $existingExternalId = $existingListing?->external_product_id
                ?? $product->externalIdForConnection($connection);

            if ($existingExternalId !== null) {
                if ($existingListing === null) {
                    $this->recordProductListing($product, $connection, $existingExternalId);
                }

                $results[] = [
                    'success' => true,
                    'platform' => $connection->platform,
                    'external_id' => $existingExternalId,
                    'variant_ids' => [],
                    'message' => 'Product is already linked to this channel.',
                    'error' => null,
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result = $connector->createVariableProduct($productData);

                if (($result['success'] ?? false) && ! empty($result['external_id'])) {
                    $productListing = $this->recordProductListing(
                        $product,
                        $connection,
                        (string) $result['external_id'],
                    );

                    foreach ($result['variant_ids'] ?? [] as $localId => $platformVariantId) {
                        if (empty($platformVariantId)) {
                            continue;
                        }

                        $variant = $product->variants()->whereKey((string) $localId)->first();
                        if ($variant === null) {
                            continue;
                        }

                        $this->recordVariantListing(
                            $variant,
                            $productListing,
                            $connection,
                            (string) $platformVariantId,
                        );
                        $this->log($connection, 'variant_create', $variant->id, true, (string) $platformVariantId, null);
                    }
                }

                $this->log(
                    $connection,
                    'product',
                    $product->id,
                    (bool) ($result['success'] ?? false),
                    $result['external_id'] ?? null,
                    $result['error'] ?? null,
                );
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, null, $e->getMessage());
                $results[] = [
                    'success' => false,
                    'platform' => $connection->platform,
                    'external_id' => '',
                    'variant_ids' => [],
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{type: string, name: string, sku: string, price: float, description: string, status: string, attributes: array<string, list<string>>, variants: list<array<string, mixed>>}
     */
    private function gatherVariableProductData(Product $product): array
    {
        $warehouse = $product->store->getPrimaryWarehouse();
        $attributeMap = [];

        $variantData = $product->variants()->get()->map(function (ProductVariant $variant) use ($product, $warehouse, &$attributeMap): array {
            $attrs = $variant->getAttribute('attributes') ?? [];

            foreach ($attrs as $name => $value) {
                foreach (is_array($value) ? $value : [(string) $value] as $item) {
                    if ($item !== '') {
                        $attributeMap[$name][] = $item;
                    }
                }
            }

            $qty = $warehouse
                ? (int) Stock::query()
                    ->where('product_id', $product->id)
                    ->where('variant_id', $variant->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('quantity')
                : $variant->getTotalStock();

            return [
                'local_id' => $variant->id,
                'name' => $variant->getDisplayName(),
                'sku' => $variant->sku ?? '',
                'price' => (float) $variant->price,
                'attributes' => $attrs,
                'stock' => $qty,
            ];
        })->all();

        foreach ($attributeMap as $name => $values) {
            $attributeMap[$name] = array_values(array_unique($values));
        }

        return [
            'type' => 'variable',
            'name' => $product->name,
            'sku' => $product->sku ?? '',
            'price' => (float) $product->price,
            'description' => $product->description ?? '',
            'status' => $product->status === 'active' ? 'active' : 'draft',
            'attributes' => $attributeMap,
            'variants' => $variantData,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function pushProduct(Product $product, ?string $platform = null): array
    {
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $externalId = $product->externalIdForConnection($connection);

            if ($externalId === null) {
                $results[] = [
                    'success' => false,
                    'platform' => $connection->platform,
                    'message' => 'Product not linked to this channel',
                    'error' => 'missing_external_id',
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result = $connector->pushProduct($product, $externalId);

                if ($result['success'] ?? false) {
                    $listing = $this->recordProductListing(
                        $product,
                        $connection,
                        (string) ($result['external_id'] ?? $externalId),
                    );
                    $listing->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
                }

                $this->log($connection, 'product', $product->id, (bool) ($result['success'] ?? false), $externalId, $result['error'] ?? null);
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, $externalId, $e->getMessage());
                $results[] = ['success' => false, 'platform' => $connection->platform, 'message' => $e->getMessage(), 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function pushStock(Product $product, ?string $platform = null): array
    {
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $productExternalId = $product->externalIdForConnection($connection);

            if ($productExternalId === null) {
                $results[] = [
                    'success' => false,
                    'message' => 'Product not linked to this channel',
                    'platform' => $connection->platform,
                    'error' => 'missing_external_id',
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $warehouse = $product->store->getPrimaryWarehouse();

                if ($product->isVariable()) {
                    $result = $this->pushVariableStock($connector, $product, $connection, $warehouse, $productExternalId);
                } else {
                    $qty = $warehouse
                        ? (int) Stock::query()
                            ->where('product_id', $product->id)
                            ->where('warehouse_id', $warehouse->id)
                            ->whereNull('variant_id')
                            ->value('quantity')
                        : $product->getTotalStock();

                    $result = $connector->pushStock($product, $qty, $productExternalId);
                    $this->log($connection, 'stock', $product->id, (bool) ($result['success'] ?? false), $productExternalId, $result['error'] ?? null);
                }

                if ($result['success'] ?? false) {
                    $product->listingForConnection($connection)?->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
                }

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'stock', $product->id, false, $productExternalId, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function pushVariantStock(ProductVariant $variant, ?string $platform = null): array
    {
        $product = $variant->product;
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $productExternalId = $product->externalIdForConnection($connection);
            $variantExternalId = $variant->externalIdForConnection($connection);

            if ($productExternalId === null || $variantExternalId === null) {
                $results[] = [
                    'success' => false,
                    'message' => 'Variant not linked to this channel',
                    'platform' => $connection->platform,
                    'error' => 'missing_external_id',
                ];
                continue;
            }

            try {
                $warehouse = $product->store->getPrimaryWarehouse();
                $qty = $warehouse
                    ? (int) Stock::query()
                        ->where('product_id', $product->id)
                        ->where('variant_id', $variant->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->value('quantity')
                    : 0;

                $connector = $this->makeConnector($connection);
                $result = $this->pushVariantStockViaConnector(
                    $connector,
                    $product,
                    $variant,
                    $qty,
                    $connection,
                    $productExternalId,
                    $variantExternalId,
                );

                if ($result['success'] ?? false) {
                    $variant->listingForConnection($connection)?->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
                }

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'stock', $variant->id, false, $variantExternalId, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return array{product: array, stock: array} */
    public function pushAllPlatforms(Product $product): array
    {
        return [
            'product' => $this->pushProduct($product),
            'stock' => $this->pushStock($product),
        ];
    }

    /** @deprecated Use pushAllPlatforms(). */
    public function pushAllChanges(Product $product): array
    {
        $variantResults = [];
        foreach ($product->variants as $variant) {
            $variantResults[] = $this->pushVariantStock($variant);
        }

        return [
            'product' => $this->pushProduct($product),
            'variants' => $variantResults,
            'stock' => $this->pushStock($product),
        ];
    }

    private function pushVariableStock(
        object $connector,
        Product $product,
        PlatformConnection $connection,
        mixed $warehouse,
        string $productExternalId,
    ): array {
        $allOk = true;
        $pushed = 0;

        foreach ($product->variants as $variant) {
            $variantExternalId = $variant->externalIdForConnection($connection);
            if ($variantExternalId === null) {
                continue;
            }

            $qty = $warehouse
                ? (int) Stock::query()
                    ->where('product_id', $product->id)
                    ->where('variant_id', $variant->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->value('quantity')
                : 0;

            $result = $this->pushVariantStockViaConnector(
                $connector,
                $product,
                $variant,
                $qty,
                $connection,
                $productExternalId,
                $variantExternalId,
            );

            if (! ($result['success'] ?? false)) {
                $allOk = false;
            } else {
                $pushed++;
                $variant->listingForConnection($connection)?->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
            }
        }

        return [
            'success' => $allOk,
            'message' => $allOk ? "Pushed stock for {$pushed} variant(s)" : 'Some variant stock pushes failed',
            'error' => $allOk ? null : 'variant_stock_failed',
        ];
    }

    private function pushVariantStockViaConnector(
        object $connector,
        Product $product,
        ProductVariant $variant,
        int $qty,
        PlatformConnection $connection,
        string $productExternalId,
        string $variantExternalId,
    ): array {
        $result = match (true) {
            $connector instanceof WooCommerceConnector => $this->wooVariantStock($connector, $qty, $productExternalId, $variantExternalId),
            $connector instanceof ShopifyConnector => $this->shopifyVariantStock($connector, $variant, $qty, $connection, $variantExternalId),
            $connector instanceof YouCanConnector => $this->youcanVariantStock($connector, $qty, $productExternalId, $variantExternalId),
            default => ['success' => false, 'message' => 'Unknown connector type', 'error' => 'unknown_connector'],
        };

        $this->log($connection, 'stock', $variant->id, (bool) ($result['success'] ?? false), $variantExternalId, $result['error'] ?? null);

        return $result;
    }

    private function wooVariantStock(WooCommerceConnector $connector, int $qty, string $productExternalId, string $variantExternalId): array
    {
        try {
            $response = $connector->client()->put(
                "/products/{$productExternalId}/variations/{$variantExternalId}",
                ['stock_quantity' => $qty, 'manage_stock' => true],
            );

            return $response->successful()
                ? ['success' => true, 'message' => "WooCommerce variant stock set to {$qty}", 'error' => null]
                : ['success' => false, 'message' => 'WooCommerce returned ' . $response->status(), 'error' => 'http_error'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()];
        }
    }

    private function shopifyVariantStock(
        ShopifyConnector $connector,
        ProductVariant $variant,
        int $qty,
        PlatformConnection $connection,
        string $variantExternalId,
    ): array {
        try {
            $inventoryItemId = $variant->listingForConnection($connection)?->external_inventory_item_id;

            if (empty($inventoryItemId)) {
                $variantResponse = $connector->client()->get("/variants/{$variantExternalId}.json");
                $inventoryItemId = $variantResponse->json('variant.inventory_item_id');
            }

            if (! $inventoryItemId) {
                return ['success' => false, 'message' => 'Shopify variant has no inventory_item_id', 'error' => 'missing_inventory_item'];
            }

            $locationResponse = $connector->client()->get('/locations.json');
            $locationId = $locationResponse->json('locations.0.id');

            if (! $locationId) {
                return ['success' => false, 'message' => 'No Shopify location found', 'error' => 'missing_location'];
            }

            $response = $connector->client()->post('/inventory_levels/set.json', [
                'location_id' => $locationId,
                'inventory_item_id' => $inventoryItemId,
                'available' => $qty,
            ]);

            return $response->successful()
                ? ['success' => true, 'message' => "Shopify variant stock set to {$qty}", 'error' => null]
                : ['success' => false, 'message' => 'Shopify returned ' . $response->status(), 'error' => 'http_error'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()];
        }
    }

    private function youcanVariantStock(YouCanConnector $connector, int $qty, string $productExternalId, string $variantExternalId): array
    {
        try {
            $response = $connector->client()->put(
                "/api/products/{$productExternalId}/variants/{$variantExternalId}",
                ['quantity' => $qty],
            );

            return $response->successful()
                ? ['success' => true, 'message' => "YouCan variant stock set to {$qty}", 'error' => null]
                : ['success' => false, 'message' => 'YouCan returned ' . $response->status(), 'error' => 'http_error'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()];
        }
    }

    private function recordProductListing(Product $product, PlatformConnection $connection, string $externalId): ProductChannelListing
    {
        $listing = ProductChannelListing::updateOrCreate(
            [
                'product_id' => $product->id,
                'platform_connection_id' => $connection->id,
            ],
            [
                'external_product_id' => $externalId,
                'sync_mode' => 'bidirectional',
                'sync_status' => 'synced',
                'last_pushed_at' => now(),
            ],
        );

        // Temporary compatibility for legacy code that has not yet moved to the
        // listing tables. Never overwrite the first mapping with another channel.
        if (empty($product->external_id)) {
            $product->forceFill(['external_id' => $externalId, 'platform' => $connection->platform])->save();
        }

        return $listing;
    }

    private function recordVariantListing(
        ProductVariant $variant,
        ProductChannelListing $productListing,
        PlatformConnection $connection,
        string $externalId,
    ): ProductVariantChannelListing {
        $listing = ProductVariantChannelListing::updateOrCreate(
            [
                'product_variant_id' => $variant->id,
                'platform_connection_id' => $connection->id,
            ],
            [
                'product_id' => $variant->product_id,
                'product_channel_listing_id' => $productListing->id,
                'external_variant_id' => $externalId,
                'sync_status' => 'synced',
                'last_pushed_at' => now(),
            ],
        );

        if (empty($variant->external_id)) {
            $variant->forceFill(['external_id' => $externalId])->save();
        }

        return $listing;
    }

    private function getConnections(Store $store, ?string $platform): Collection
    {
        return $store->connections()
            ->where('status', 'active')
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->get();
    }

    private function makeConnector(PlatformConnection $connection): object
    {
        return match ($connection->platform) {
            'woocommerce' => new WooCommerceConnector($connection),
            'shopify' => new ShopifyConnector($connection),
            'youcan' => new YouCanConnector($connection),
            default => throw new \InvalidArgumentException("Unknown platform: {$connection->platform}"),
        };
    }

    private function log(
        PlatformConnection $connection,
        string $action,
        string $entityId,
        bool $success,
        ?string $externalId,
        ?string $error,
    ): void {
        try {
            SyncLog::create([
                'store_id' => $connection->store_id,
                'platform_connection_id' => $connection->id,
                'platform' => $connection->platform,
                'type' => 'push',
                'direction' => 'push',
                'action' => $action,
                'entity_id' => $entityId,
                'external_id' => $externalId,
                'status' => $success ? 'success' : 'failed',
                'error_message' => $error,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProductPushService: failed to write SyncLog', ['error' => $e->getMessage()]);
        }
    }
}
