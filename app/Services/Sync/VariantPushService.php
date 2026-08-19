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

class VariantPushService
{
    /**
     * Create a brand-new variant on ALL active platform connections (or one if $platform specified).
     * Resolves platform-specific ids from channel listing tables.
     *
     * @return array<int, array{success: bool, message: string, platform: string, external_id?: string}>
     */
    public function createVariant(ProductVariant $variant, ?string $platform = null): array
    {
        $product = $variant->product;
        $results = [];

        // Intentionally NOT using $product->platform here — that stores only the FIRST platform
        // a product was pushed to. We want to push to ALL active connections.
        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $productExternalId = $product->externalIdForConnection($connection);

            if (empty($productExternalId)) {
                $results[] = [
                    'success'  => false,
                    'message'  => 'Product not synced to this platform — push the product first',
                    'platform' => $connection->platform,
                ];
                continue;
            }

            $existingVariantExternalId = $variant->externalIdForConnection($connection);
            if (! empty($existingVariantExternalId)) {
                $productListing = $product->listingForConnection($connection);

                if ($productListing === null) {
                    $productListing = ProductChannelListing::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'platform_connection_id' => $connection->id,
                        ],
                        [
                            'external_product_id' => $productExternalId,
                            'sync_status' => 'synced',
                        ],
                    );
                }

                if ($variant->listingForConnection($connection) === null) {
                    ProductVariantChannelListing::updateOrCreate(
                        [
                            'product_variant_id' => $variant->id,
                            'platform_connection_id' => $connection->id,
                        ],
                        [
                            'product_id' => $product->id,
                            'product_channel_listing_id' => $productListing->id,
                            'external_variant_id' => $existingVariantExternalId,
                            'sync_status' => 'synced',
                        ],
                    );
                }

                $results[] = [
                    'success' => true,
                    'message' => 'Variant is already linked to this channel.',
                    'platform' => $connection->platform,
                    'external_id' => $existingVariantExternalId,
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result    = $connector->createVariant($variant, $productExternalId);

                if ($result['success'] && ! empty($result['external_id'])) {
                    $productListing = $product->listingForConnection($connection);

                    if ($productListing !== null) {
                        ProductVariantChannelListing::updateOrCreate(
                            [
                                'product_variant_id' => $variant->id,
                                'platform_connection_id' => $connection->id,
                            ],
                            [
                                'product_id' => $product->id,
                                'product_channel_listing_id' => $productListing->id,
                                'external_variant_id' => (string) $result['external_id'],
                                'sync_status' => 'synced',
                                'last_pushed_at' => now(),
                            ],
                        );
                    }

                    // Legacy compatibility only; per-channel listing is authoritative.
                    if (empty($variant->external_id)) {
                        $variant->forceFill(['external_id' => (string) $result['external_id']])->save();
                    }
                }

                $this->log($connection, 'variant_create', $variant->id, $result['success'], $result['external_id'] ?? null, $result['error'] ?? null);
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'variant_create', $variant->id, false, null, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform];
                Log::warning('VariantPushService::createVariant failed', [
                    'variant'  => $variant->id,
                    'platform' => $connection->platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Push variant field changes (name, price, sku, attributes) to one or all platforms.
     *
     * @return array<int, array{success: bool, message: string, platform: string}>
     */
    public function pushVariant(ProductVariant $variant, ?string $platform = null): array
    {
        $product = $variant->product;
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $variantExternalId = $variant->externalIdForConnection($connection);
            $productExternalId = $product->externalIdForConnection($connection);

            if (empty($variantExternalId)) {
                $results[] = ['success' => false, 'message' => 'Variant not linked to this platform', 'platform' => $connection->platform];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result    = $connector->pushProductVariant($variant, $productExternalId, $variantExternalId);

                if ($result['success'] ?? false) {
                    $variant->listingForConnection($connection)?->update([
                        'last_pushed_at' => now(),
                        'sync_status' => 'synced',
                    ]);
                }

                $this->log($connection, 'variant', $variant->id, $result['success'], $variantExternalId, $result['error'] ?? null);
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'variant', $variant->id, false, $variantExternalId, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform];
                Log::warning('VariantPushService::pushVariant failed', [
                    'variant'  => $variant->id,
                    'platform' => $connection->platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Push a single variant's stock quantity to one or all platforms.
     *
     * @return array<int, array{success: bool, message: string, platform: string}>
     */
    public function pushVariantStock(ProductVariant $variant, ?string $platform = null): array
    {
        $product = $variant->product;
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $variantExternalId = $variant->externalIdForConnection($connection);
            $productExternalId = $product->externalIdForConnection($connection);

            if (empty($variantExternalId)) {
                $results[] = ['success' => false, 'message' => 'Variant not linked to this platform', 'platform' => $connection->platform];
                continue;
            }

            try {
                $warehouse = $product->store->getPrimaryWarehouse();
                $qty       = $warehouse
                    ? (int) Stock::where('product_id', $product->id)
                        ->where('variant_id', $variant->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->value('quantity')
                    : $variant->getTotalStock();

                $connector = $this->makeConnector($connection);
                $result    = $this->pushVariantStockViaConnector($connector, $product, $variant, $qty, $connection, $productExternalId, $variantExternalId);

                if ($result['success'] ?? false) {
                    $variant->listingForConnection($connection)?->update([
                        'last_pushed_at' => now(),
                        'sync_status' => 'synced',
                    ]);
                }

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'stock', $variant->id, false, $variantExternalId, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform];
                Log::warning('VariantPushService::pushVariantStock failed', [
                    'variant'  => $variant->id,
                    'platform' => $connection->platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Delete a variant from one or all connected platforms.
     *
     * @return array<int, array{success: bool, message: string, platform: string}>
     */
    public function deleteVariant(ProductVariant $variant, ?string $platform = null): array
    {
        $product = $variant->product;
        $results = [];

        foreach ($this->getConnections($product->store, $platform) as $connection) {
            $variantExternalId = $variant->externalIdForConnection($connection);

            if (empty($variantExternalId)) {
                $results[] = ['success' => true, 'message' => 'Variant not on this platform, nothing to delete', 'platform' => $connection->platform];
                continue;
            }

            try {
                $productExternalId = $product->externalIdForConnection($connection);
                $connector         = $this->makeConnector($connection);
                $result            = $connector->deleteVariant($variant, $productExternalId, $variantExternalId);

                if ($result['success'] ?? false) {
                    $variant->channelListings()
                        ->where('platform_connection_id', $connection->id)
                        ->delete();
                }

                $this->log($connection, 'variant_delete', $variant->id, $result['success'], $variantExternalId, $result['error'] ?? null);
                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->log($connection, 'variant_delete', $variant->id, false, $variantExternalId, $e->getMessage());
                $results[] = ['success' => false, 'message' => $e->getMessage(), 'platform' => $connection->platform];
                Log::warning('VariantPushService::deleteVariant failed', [
                    'variant'  => $variant->id,
                    'platform' => $connection->platform,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    // ──────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────

    /** Listing tables are the source of truth for remote ids. */
    private function pushVariantStockViaConnector(
        object $connector,
        Product $product,
        ProductVariant $variant,
        int $qty,
        PlatformConnection $connection,
        ?string $productExternalId,
        ?string $variantExternalId
    ): array {
        $result = match (true) {
            $connector instanceof WooCommerceConnector => $this->wooVariantStock($connector, $product, $variant, $qty, $productExternalId, $variantExternalId),
            $connector instanceof ShopifyConnector     => $this->shopifyVariantStock($connector, $variant, $qty, $variantExternalId),
            $connector instanceof YouCanConnector      => $this->youcanVariantStock($connector, $product, $variant, $qty, $productExternalId, $variantExternalId),
            default                                    => ['success' => false, 'message' => 'Unknown connector type'],
        };

        $this->log(
            $connection,
            'stock',
            $variant->id,
            $result['success'],
            $variantExternalId,
            $result['success'] ? null : ($result['message'] ?? null)
        );

        return $result;
    }

    private function wooVariantStock(WooCommerceConnector $connector, Product $product, ProductVariant $variant, int $qty, ?string $productExternalId, ?string $variantExternalId): array
    {
        $pid = $productExternalId ?? $product->external_id;
        $vid = $variantExternalId ?? $variant->external_id;

        try {
            $response = $connector->client()->put(
                "/products/{$pid}/variations/{$vid}",
                ['stock_quantity' => $qty, 'manage_stock' => true]
            );

            return $response->successful()
                ? ['success' => true,  'message' => "WooCommerce variant stock set to {$qty}"]
                : ['success' => false, 'message' => 'WooCommerce returned ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function shopifyVariantStock(ShopifyConnector $connector, ProductVariant $variant, int $qty, ?string $variantExternalId): array
    {
        $vid = $variantExternalId ?? $variant->external_id;

        try {
            $variantResponse = $connector->client()->get("/variants/{$vid}.json");
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

    private function youcanVariantStock(YouCanConnector $connector, Product $product, ProductVariant $variant, int $qty, ?string $productExternalId, ?string $variantExternalId): array
    {
        $pid = $productExternalId ?? $product->external_id;
        $vid = $variantExternalId ?? $variant->external_id;

        try {
            $response = $connector->client()->put(
                "/api/products/{$pid}/variants/{$vid}",
                ['quantity' => $qty]
            );

            return $response->successful()
                ? ['success' => true,  'message' => "YouCan variant stock set to {$qty}"]
                : ['success' => false, 'message' => 'YouCan returned ' . $response->status()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

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
            Log::warning('VariantPushService: failed to write SyncLog', ['error' => $e->getMessage()]);
        }
    }
}
