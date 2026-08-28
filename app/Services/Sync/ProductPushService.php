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
    public function createProduct(Product $product, ?string $platform = null, ?array $connectionIds = null): array
    {
        if ($product->isVariable()) {
            return $this->createVariableProduct($product, $platform, $connectionIds);
        }

        $results = [];

        foreach ($this->getConnections($product->store, $platform, $connectionIds) as $connection) {
            $existingListing = $product->listingForConnection($connection);
            $existingExternalId = $existingListing?->external_product_id
                ?? $product->externalIdForConnection($connection);

            if ($existingExternalId !== null) {
                // Self-heal legacy products whose old external_id/platform mapping
                // exists but could not be backfilled when the migration ran.
                if ($existingListing === null) {
                    $existingListing = $this->recordProductListing($product, $connection, $existingExternalId);
                }

                $results[] = [
                    'success' => true,
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'listing_id' => $existingListing->id,
                    'external_id' => $existingExternalId,
                    'message' => 'Product is already linked to this channel.',
                    'error' => null,
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result = $connector->createProduct($product);
                $listingId = null;

                if (($result['success'] ?? false) && ! empty($result['external_id'])) {
                    $listingId = $this->recordProductListing($product, $connection, (string) $result['external_id'])->id;
                } elseif ($result['success'] ?? false) {
                    // The platform answered 2xx but the response carried no
                    // usable id — treating this as "success" is exactly how a
                    // product ends up unlinked-but-looks-published, and the
                    // next sync creates a duplicate because nothing here ever
                    // recorded a ProductChannelListing to match against.
                    $result['success'] = false;
                    $result['error'] = 'missing_external_id';
                    $result['message'] = 'Platform did not return a product id — nothing was linked.';
                }

                $this->log(
                    $connection,
                    'product',
                    $product->id,
                    (bool) ($result['success'] ?? false),
                    $result['external_id'] ?? null,
                    $result['error'] ?? null,
                );

                $results[] = array_merge($result, ['connection_id' => $connection->id, 'platform' => $connection->platform, 'listing_id' => $listingId]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, null, $e->getMessage());
                $results[] = [
                    'success' => false,
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'listing_id' => null,
                    'external_id' => '',
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    public function createVariableProduct(Product $product, ?string $platform = null, ?array $connectionIds = null): array
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

        foreach ($this->getConnections($product->store, $platform, $connectionIds) as $connection) {
            $existingListing = $product->listingForConnection($connection);
            $existingExternalId = $existingListing?->external_product_id
                ?? $product->externalIdForConnection($connection);

            if ($existingExternalId !== null) {
                if ($existingListing === null) {
                    $existingListing = $this->recordProductListing($product, $connection, $existingExternalId);
                }

                $results[] = [
                    'success' => true,
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'listing_id' => $existingListing->id,
                    'external_id' => $existingExternalId,
                    'variant_ids' => [],
                    'message' => 'Product is already linked to this channel.',
                    'error' => null,
                ];
                continue;
            }

            $listingId = null;

            try {
                $connector = $this->makeConnector($connection);
                $result = $connector->createVariableProduct($productData);

                if (($result['success'] ?? false) && ! empty($result['external_id'])) {
                    $productListing = $this->recordProductListing(
                        $product,
                        $connection,
                        (string) $result['external_id'],
                    );
                    $listingId = $productListing->id;

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
                $results[] = array_merge($result, ['connection_id' => $connection->id, 'platform' => $connection->platform, 'listing_id' => $listingId]);
            } catch (\Throwable $e) {
                $this->log($connection, 'product', $product->id, false, null, $e->getMessage());
                $results[] = [
                    'success' => false,
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'listing_id' => null,
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

    /**
     * @param  array<int, string>|null  $connectionIds  Explicit target connections.
     *         null keeps the legacy "every active connection for the platform"
     *         behavior for existing callers (pushAllPlatforms/pushAllChanges) —
     *         ProductPublishService always passes an explicit list.
     * @return array<int, array<string, mixed>>
     */
    public function pushProduct(Product $product, ?string $platform = null, ?array $connectionIds = null): array
    {
        $results = [];

        foreach ($this->getConnections($product->store, $platform, $connectionIds) as $connection) {
            $externalId = $product->externalIdForConnection($connection);

            if ($externalId === null) {
                $results[] = [
                    'success' => false,
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'listing_id' => null,
                    'message' => 'Product not linked to this channel',
                    'error' => 'missing_external_id',
                ];
                continue;
            }

            try {
                $connector = $this->makeConnector($connection);
                $result = $connector->pushProduct($product, $externalId);
                $listingId = null;

                if ($result['success'] ?? false) {
                    $listing = $this->recordProductListing(
                        $product,
                        $connection,
                        (string) ($result['external_id'] ?? $externalId),
                    );
                    $listing->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
                    $listingId = $listing->id;

                    // WooCommerce-only: parent fields are pushed above; variant
                    // fields (sku/price) still need their own PUT per variation.
                    // Never reachable for Shopify/YouCan connectors.
                    if ($connector instanceof WooCommerceConnector && $product->isVariable()) {
                        $this->pushVariantFields($connector, $product, $connection, $externalId);
                    }
                } else {
                    $this->markListingFailed($product, $connection, (string) ($result['error'] ?? $result['message'] ?? 'push_failed'));
                    $listingId = $product->listingForConnection($connection)?->id;
                }

                $this->log($connection, 'product', $product->id, (bool) ($result['success'] ?? false), $externalId, $result['error'] ?? null);
                $results[] = array_merge($result, ['connection_id' => $connection->id, 'platform' => $connection->platform, 'listing_id' => $listingId]);
            } catch (\Throwable $e) {
                $this->markListingFailed($product, $connection, $e->getMessage());
                $this->log($connection, 'product', $product->id, false, $externalId, $e->getMessage());
                $results[] = [
                    'success' => false,
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'listing_id' => $product->listingForConnection($connection)?->id,
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Push each variant's sku/price to its WooCommerce variation. Only called
     * for WooCommerce connections after a successful parent-product push —
     * WooCommerceConnector::pushProductVariant() already exists and already
     * does the right thing, it was simply never invoked from here.
     */
    private function pushVariantFields(
        WooCommerceConnector $connector,
        Product $product,
        PlatformConnection $connection,
        string $productExternalId,
    ): void {
        foreach ($product->variants as $variant) {
            $variantExternalId = $variant->externalIdForConnection($connection);

            if ($variantExternalId === null) {
                continue;
            }

            try {
                $result = $connector->pushProductVariant($variant, $productExternalId, $variantExternalId);

                $listing = $variant->listingForConnection($connection);

                if ($result['success'] ?? false) {
                    $listing?->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
                } else {
                    $listing?->update([
                        'sync_status' => 'failed',
                        'metadata' => array_merge($listing->metadata ?? [], ['last_push_error' => $result['error'] ?? $result['message'] ?? 'push_failed']),
                    ]);
                }

                $this->log($connection, 'variant', $variant->id, (bool) ($result['success'] ?? false), $variantExternalId, $result['error'] ?? null);
            } catch (\Throwable $e) {
                $variant->listingForConnection($connection)?->update([
                    'sync_status' => 'failed',
                    'metadata' => array_merge($variant->listingForConnection($connection)->metadata ?? [], ['last_push_error' => $e->getMessage()]),
                ]);
                $this->log($connection, 'variant', $variant->id, false, $variantExternalId, $e->getMessage());
            }
        }
    }

    private function markListingFailed(Product $product, PlatformConnection $connection, string $error): void
    {
        $listing = $product->listingForConnection($connection);

        $listing?->update([
            'sync_status' => 'failed',
            'metadata' => array_merge($listing->metadata ?? [], ['last_push_error' => $error]),
        ]);
    }

    private function markVariantListingFailed(ProductVariant $variant, PlatformConnection $connection, string $error): void
    {
        $listing = $variant->listingForConnection($connection);

        $listing?->update([
            'sync_status' => 'failed',
            'metadata' => array_merge($listing->metadata ?? [], ['last_push_error' => $error]),
        ]);
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

                    // Shopify quantity is never set on the product/variant
                    // payload — it lives on InventoryLevel, keyed by
                    // inventory_item_id + location_id. shopifySimpleStock()
                    // resolves both (from cached listing metadata first,
                    // Shopify only if missing) and calls
                    // inventory_levels/set.json explicitly.
                    $result = $connector instanceof ShopifyConnector
                        ? $this->shopifySimpleStock($connector, $product, $connection, $qty, $productExternalId)
                        : $connector->pushStock($product, $qty, $productExternalId);
                    $this->log($connection, 'stock', $product->id, (bool) ($result['success'] ?? false), $productExternalId, $result['error'] ?? null);
                }

                if ($result['success'] ?? false) {
                    $product->listingForConnection($connection)?->update(['last_pushed_at' => now(), 'sync_status' => 'synced']);
                } else {
                    $this->markListingFailed($product, $connection, (string) ($result['error'] ?? $result['message'] ?? 'stock_push_failed'));
                }

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->markListingFailed($product, $connection, $e->getMessage());
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
                } else {
                    $this->markVariantListingFailed($variant, $connection, (string) ($result['error'] ?? $result['message'] ?? 'stock_push_failed'));
                }

                $results[] = array_merge($result, ['platform' => $connection->platform]);
            } catch (\Throwable $e) {
                $this->markVariantListingFailed($variant, $connection, $e->getMessage());
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

    /**
     * Shopify quantity is never set via a product/variant update payload —
     * it lives on InventoryLevel, keyed by inventory_item_id + location_id
     * (POST /inventory_levels/set.json). This resolves both, preferring
     * already-known values (ProductVariantChannelListing.external_inventory_item_id,
     * PlatformConnection.metadata.location_id) over a live Shopify fetch,
     * and persists whichever it had to fetch so the next push skips it.
     */
    private function shopifyVariantStock(
        ShopifyConnector $connector,
        ProductVariant $variant,
        int $qty,
        PlatformConnection $connection,
        string $variantExternalId,
    ): array {
        try {
            $listing = $variant->listingForConnection($connection);
            $inventoryItemId = $listing?->external_inventory_item_id;

            if (empty($inventoryItemId)) {
                $inventoryItemId = $connector->getVariantInventoryItemId($variantExternalId);

                if (empty($inventoryItemId)) {
                    return ['success' => false, 'message' => 'Shopify variant has no inventory_item_id', 'error' => 'missing_inventory_item'];
                }

                $listing?->update(['external_inventory_item_id' => $inventoryItemId]);
            }

            $locationId = $connector->resolveLocationId();

            if ($locationId === null) {
                return ['success' => false, 'message' => 'No Shopify location found for inventory sync.', 'error' => 'missing_location'];
            }

            // Throws on failure (like updateVariantPayload/updateProductPayload)
            // rather than returning success:false — caught below.
            $connector->setInventoryLevel((string) $inventoryItemId, $locationId, $qty);

            return ['success' => true, 'message' => "Shopify variant stock set to {$qty}", 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()];
        }
    }

    /**
     * Simple-product counterpart to shopifyVariantStock() — resolves the
     * default variant's inventory_item_id from
     * ProductChannelListing.metadata.default_inventory_item_id first,
     * fetching and persisting it only when missing.
     */
    private function shopifySimpleStock(
        ShopifyConnector $connector,
        Product $product,
        PlatformConnection $connection,
        int $qty,
        string $productExternalId,
    ): array {
        try {
            $listing = $product->listingForConnection($connection);
            $inventoryItemId = $listing !== null ? ($listing->metadata['default_inventory_item_id'] ?? null) : null;

            if (empty($inventoryItemId)) {
                $inventoryItemId = $connector->getDefaultVariantInventoryItemId($productExternalId);

                if (empty($inventoryItemId)) {
                    return ['success' => false, 'message' => 'Shopify product has no inventory_item_id', 'error' => 'missing_inventory_item'];
                }

                if ($listing !== null) {
                    $metadata = $listing->metadata ?? [];
                    $metadata['default_inventory_item_id'] = $inventoryItemId;
                    $listing->update(['metadata' => $metadata]);
                }
            }

            $locationId = $connector->resolveLocationId();

            if ($locationId === null) {
                return ['success' => false, 'message' => 'No Shopify location found for inventory sync.', 'error' => 'missing_location'];
            }

            $connector->setInventoryLevel((string) $inventoryItemId, $locationId, $qty);

            return ['success' => true, 'message' => "Shopify stock set to {$qty}", 'error' => null];
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

    /**
     * @param  array<int, string>|null  $connectionIds  When given, scopes to
     *         EXACTLY these connections — the explicit-targeting primitive
     *         ProductPublishService relies on. Never used to silently widen
     *         a request to "every connected platform".
     */
    private function getConnections(Store $store, ?string $platform, ?array $connectionIds = null): Collection
    {
        return $store->connections()
            ->where('status', 'active')
            ->when($platform, fn ($query) => $query->where('platform', $platform))
            ->when($connectionIds !== null, fn ($query) => $query->whereIn('id', $connectionIds))
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
