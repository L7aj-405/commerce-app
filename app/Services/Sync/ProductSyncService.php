<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Connectors\YouCanConnector;
use App\Enums\StockMovementType;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ProductSyncService
{
    public function syncFromPlatform(Store $store, string $platform): array
    {
        $connection = $store->connections()
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();

        if ($connection === null) {
            Log::warning('No active connection found for platform', [
                'store' => $store->id,
                'platform' => $platform,
            ]);

            return ['created' => 0, 'updated' => 0, 'failed' => 0];
        }

        $connector = $this->getConnector($platform, $connection);
        $created = 0;
        $updated = 0;
        $failed = 0;
        $page = 1;
        $perPage = 50;
        $seenPages = [];

        while (true) {
            Log::info("Fetching products page {$page}", [
                'store' => $store->id,
                'platform' => $platform,
                'connection' => $connection->id,
            ]);

            try {
                $products = $connector->getProducts($page, $perPage);
            } catch (\Throwable $e) {
                Log::error("Error fetching products page {$page}", [
                    'error' => $e->getMessage(),
                    'store' => $store->id,
                    'connection' => $connection->id,
                ]);
                break;
            }

            if (empty($products)) {
                break;
            }

            // Protect against connectors that ignore the requested page and keep
            // returning the same records forever.
            $fingerprint = hash('sha256', json_encode(array_map(
                static fn (array $product): string => (string) ($product['external_id'] ?? ''),
                $products,
            )) ?: '');

            if (isset($seenPages[$fingerprint])) {
                Log::warning('Stopping product sync because a connector repeated the same page', [
                    'store' => $store->id,
                    'platform' => $platform,
                    'page' => $page,
                ]);
                break;
            }
            $seenPages[$fingerprint] = true;

            foreach ($products as $platformProduct) {
                try {
                    $result = $this->saveProduct($platformProduct, $store, $platform, $connection);

                    if ($result === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }

                    if (
                        ($platformProduct['type'] ?? 'simple') === 'variable'
                        && empty($platformProduct['variants'])
                        && method_exists($connector, 'getProductVariants')
                    ) {
                        $this->syncVariantsForProduct(
                            $connector,
                            (string) ($platformProduct['external_id'] ?? ''),
                            $store,
                            $connection,
                        );
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Failed to sync product', [
                        'sku' => $platformProduct['sku'] ?? null,
                        'external_id' => $platformProduct['external_id'] ?? null,
                        'error' => $e->getMessage(),
                        'store' => $store->id,
                        'connection' => $connection->id,
                    ]);
                }
            }

            if (count($products) < $perPage) {
                break;
            }

            $page++;
        }

        Log::info('Product sync completed', [
            'store' => $store->id,
            'platform' => $platform,
            'connection' => $connection->id,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
        ]);

        return compact('created', 'updated', 'failed');
    }

    private function getConnector(string $platform, PlatformConnection $connection): object
    {
        return match ($platform) {
            'woocommerce' => new WooCommerceConnector($connection),
            'shopify' => new ShopifyConnector($connection),
            'youcan' => new YouCanConnector($connection),
            default => throw new InvalidArgumentException("Unknown platform: {$platform}"),
        };
    }

    private function syncVariantsForProduct(
        object $connector,
        string $externalId,
        Store $store,
        PlatformConnection $connection,
    ): void {
        if ($externalId === '') {
            return;
        }

        $product = ProductChannelListing::query()
            ->where('platform_connection_id', $connection->id)
            ->where('external_product_id', $externalId)
            ->with('product')
            ->first()?->product;

        // Migration compatibility for a legacy row that has not been backfilled
        // because its connection was missing at migration time.
        $product ??= Product::query()
            ->where('store_id', $store->id)
            ->where('platform', $connection->platform)
            ->where('external_id', $externalId)
            ->first();

        if ($product === null) {
            return;
        }

        $variantPage = 1;
        $perPage = 100;
        $seenVariantPages = [];

        while (true) {
            try {
                $variants = $connector->getProductVariants($externalId, $variantPage, $perPage);

                if (empty($variants)) {
                    break;
                }

                $fingerprint = hash('sha256', json_encode(array_map(
                    static fn (array $variant): string => (string) ($variant['external_id'] ?? ''),
                    $variants,
                )) ?: '');

                if (isset($seenVariantPages[$fingerprint])) {
                    Log::warning('Stopping variant sync because a connector repeated the same page', [
                        'product_id' => $product->id,
                        'connection' => $connection->id,
                        'page' => $variantPage,
                    ]);
                    break;
                }
                $seenVariantPages[$fingerprint] = true;

                $this->createVariants($product, $variants, $connection);

                if (count($variants) < $perPage) {
                    break;
                }

                $variantPage++;
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch variants for product', [
                    'product_id' => $product->id,
                    'external_id' => $externalId,
                    'page' => $variantPage,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }
    }

    /**
     * Pull one remote product into the canonical Store catalog.
     *
     * Identity order:
     *  1) channel listing (authoritative),
     *  2) legacy external_id/platform,
     *  3) same-store SKU (links another channel to the existing product),
     *  4) create a new canonical product.
     */
    public function saveProduct(
        array $platformProduct,
        Store $store,
        string $platform,
        ?PlatformConnection $connection = null,
    ): string {
        $connection ??= $store->connections()
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();

        if ($connection === null || $connection->store_id !== $store->id) {
            throw new InvalidArgumentException('No valid platform connection for this store.');
        }

        $externalId = trim((string) ($platformProduct['external_id'] ?? ''));
        if ($externalId === '') {
            throw new InvalidArgumentException('Remote product is missing external_id.');
        }

        return DB::transaction(function () use ($platformProduct, $store, $platform, $connection, $externalId): string {
            $warehouse = $this->getOrCreateWarehouse($store);
            $remoteSku = $this->nullableString($platformProduct['sku'] ?? null);

            $listing = ProductChannelListing::query()
                ->where('platform_connection_id', $connection->id)
                ->where('external_product_id', $externalId)
                ->with('product')
                ->first();

            $product = $listing?->product;
            $authoritativePull = $listing !== null;

            if ($product === null) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('platform', $platform)
                    ->where('external_id', $externalId)
                    ->first();

                $authoritativePull = $product !== null;
            }

            // SKU is the safe automatic merge key inside one Store catalog. It
            // lets Shopify + WooCommerce point to the same canonical product.
            if ($product === null && $remoteSku !== null) {
                $product = Product::query()
                    ->where('store_id', $store->id)
                    ->where('sku', $remoteSku)
                    ->first();
            }

            $wasCreated = $product === null;
            $canonicalData = $this->canonicalProductData($platformProduct, $remoteSku);

            if ($product === null) {
                $product = Product::create(array_merge($canonicalData, [
                    'store_id' => $store->id,
                    // Deprecated compatibility fields; channel listings are the
                    // actual mapping from now on.
                    'external_id' => $externalId,
                    'platform' => $platform,
                ]));
                $authoritativePull = true;
            } elseif ($authoritativePull) {
                $product->update($canonicalData);
            } else {
                // A new channel matched an existing canonical SKU. Linking the
                // channel must not silently let that channel overwrite ERP data.
                $product->forceFill(['synced_at' => now()])->save();
            }

            $listing = ProductChannelListing::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'platform_connection_id' => $connection->id,
                ],
                [
                    'external_product_id' => $externalId,
                    'external_handle' => $this->nullableString($platformProduct['handle'] ?? $platformProduct['slug'] ?? null),
                    'sync_status' => 'synced',
                    'last_pulled_at' => now(),
                    'remote_updated_at' => $platformProduct['updated_at'] ?? null,
                    'metadata' => is_array($platformProduct['metadata'] ?? null) ? $platformProduct['metadata'] : [],
                ],
            );

            // Keep first-platform legacy columns populated for old commands/UI
            // during the migration window, but never use them as multi-channel truth.
            if (empty($product->external_id)) {
                $product->forceFill([
                    'external_id' => $externalId,
                    'platform' => $platform,
                ])->save();
            }

            // Preserve old pull semantics for a product already owned by this
            // channel, and for newly created products. A second channel linked by
            // SKU does not get to overwrite stock on first contact.
            if (($authoritativePull || $wasCreated) && ! $product->isVariable()) {
                $this->updateOrCreateStock(
                    $product,
                    $warehouse,
                    (int) ($platformProduct['stock'] ?? $platformProduct['stock_quantity'] ?? 0),
                );
            }

            // Always process remote variants. On a newly linked second channel,
            // createVariants() matches by SKU and records the channel mapping
            // without overwriting the canonical variant fields or stock.
            if (! empty($platformProduct['variants']) && is_array($platformProduct['variants'])) {
                $this->createVariants($product, $platformProduct['variants'], $connection);
            }

            return $wasCreated ? 'created' : 'updated';
        });
    }

    /** @return array<string, mixed> */
    private function canonicalProductData(array $platformProduct, ?string $remoteSku): array
    {
        return [
            'name' => trim((string) ($platformProduct['name'] ?? '')) ?: 'Unnamed',
            'description' => $platformProduct['description'] ?? '',
            'sku' => $remoteSku,
            'barcode' => $this->nullableString($platformProduct['barcode'] ?? null),
            'category' => $this->nullableString($platformProduct['category'] ?? null),
            'type' => ($platformProduct['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple',
            'price' => (float) ($platformProduct['price'] ?? 0),
            'cost' => (float) ($platformProduct['cost'] ?? 0),
            'compare_price' => isset($platformProduct['compare_price']) ? (float) $platformProduct['compare_price'] : null,
            'status' => in_array($platformProduct['status'] ?? null, ['active', 'draft', 'archived'], true)
                ? $platformProduct['status']
                : 'draft',
            'featured_image' => $platformProduct['featured_image'] ?? null,
            'images' => is_array($platformProduct['images'] ?? null) ? $platformProduct['images'] : [],
            'metadata' => is_array($platformProduct['metadata'] ?? null) ? $platformProduct['metadata'] : [],
            'synced_at' => now(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function createStocks(Product $product, Warehouse $warehouse, int $quantity = 0): void
    {
        if ($product->isVariable()) {
            return;
        }

        $this->updateOrCreateStock($product, $warehouse, $quantity);
    }

    public function updateStock(Product $product, Warehouse $warehouse, int $quantity): void
    {
        $this->updateOrCreateStock($product, $warehouse, $quantity);
    }

    private function updateOrCreateStock(Product $product, Warehouse $warehouse, int $quantity): void
    {
        try {
            $stock = Stock::firstOrNew([
                'product_id' => $product->id,
                'variant_id' => null,
                'warehouse_id' => $warehouse->id,
            ]);

            $oldQty = (int) ($stock->exists ? $stock->quantity : 0);
            $stock->quantity = $quantity;
            $stock->save();

            if ($oldQty !== $quantity) {
                StockMovement::create([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'user_id' => $warehouse->user_id,
                    'type' => StockMovementType::Adjustment->value,
                    'quantity' => $quantity - $oldQty,
                    'notes' => $stock->wasRecentlyCreated
                        ? "Initial stock from platform sync: {$quantity} units"
                        : "Stock updated from platform sync: {$oldQty} → {$quantity}",
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to sync product stock', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $platformVariants
     */
    public function createVariants(
        Product $product,
        array $platformVariants,
        ?PlatformConnection $connection = null,
    ): void {
        $connection ??= $product->platform
            ? $product->store?->connections()->where('platform', $product->platform)->first()
            : null;

        $productListing = $connection !== null ? $product->listingForConnection($connection) : null;

        foreach ($platformVariants as $platformVariant) {
            if (empty($platformVariant)) {
                continue;
            }

            try {
                $externalId = trim((string) ($platformVariant['external_id'] ?? ''));
                $remoteSku = $this->nullableString($platformVariant['sku'] ?? null);
                $listing = null;
                $variant = null;
                $authoritativePull = false;

                if ($connection !== null && $externalId !== '') {
                    $listing = ProductVariantChannelListing::query()
                        ->where('platform_connection_id', $connection->id)
                        ->where('external_variant_id', $externalId)
                        ->with('variant')
                        ->first();
                    $variant = $listing?->variant;
                    $authoritativePull = $listing !== null;
                }

                if (
                    $variant === null
                    && $externalId !== ''
                    && ($connection === null || $product->platform === $connection->platform)
                ) {
                    // Legacy external_id belongs only to the product's first/legacy
                    // platform. Never let an id collision on a second channel turn
                    // that channel into an authoritative overwrite.
                    $variant = $product->variants()
                        ->where('external_id', $externalId)
                        ->first();
                    $authoritativePull = $variant !== null;
                }

                if ($variant === null && $remoteSku !== null) {
                    $variant = $product->variants()->where('sku', $remoteSku)->first();
                }

                $wasCreated = $variant === null;
                $variantData = [
                    'name' => trim((string) ($platformVariant['name'] ?? '')) ?: 'Variant',
                    'sku' => $remoteSku,
                    'price' => (float) ($platformVariant['price'] ?? 0),
                    'cost' => (float) ($platformVariant['cost'] ?? 0),
                    'compare_price' => isset($platformVariant['compare_price']) ? (float) $platformVariant['compare_price'] : null,
                    'attributes' => is_array($platformVariant['attributes'] ?? null) ? $platformVariant['attributes'] : [],
                ];

                if ($variant === null) {
                    $variant = $product->variants()->create(array_merge($variantData, [
                        'external_id' => $externalId !== '' ? $externalId : null,
                    ]));
                    $authoritativePull = true;
                } elseif ($authoritativePull) {
                    $variant->update($variantData);
                }

                if ($connection !== null && $externalId !== '') {
                    $productListing ??= ProductChannelListing::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'platform_connection_id' => $connection->id,
                        ],
                        [
                            'external_product_id' => $product->externalIdForConnection($connection) ?? (string) $product->external_id,
                            'sync_status' => 'synced',
                            'last_pulled_at' => now(),
                        ],
                    );

                    ProductVariantChannelListing::updateOrCreate(
                        [
                            'product_variant_id' => $variant->id,
                            'platform_connection_id' => $connection->id,
                        ],
                        [
                            'product_id' => $product->id,
                            'product_channel_listing_id' => $productListing->id,
                            'external_variant_id' => $externalId,
                            'external_inventory_item_id' => $this->nullableString($platformVariant['inventory_item_id'] ?? null),
                            'sync_status' => 'synced',
                            'last_pulled_at' => now(),
                            'remote_updated_at' => $platformVariant['updated_at'] ?? null,
                            'metadata' => is_array($platformVariant['metadata'] ?? null) ? $platformVariant['metadata'] : [],
                        ],
                    );
                }

                if (empty($variant->external_id) && $externalId !== '') {
                    $variant->forceFill(['external_id' => $externalId])->save();
                }

                if (($authoritativePull || $wasCreated) && isset($platformVariant['stock']) && $platformVariant['stock'] !== null) {
                    $this->syncVariantStock($variant, (int) $platformVariant['stock']);
                }

                if (($authoritativePull || $wasCreated) && ! empty($platformVariant['attributes']) && is_array($platformVariant['attributes'])) {
                    $this->syncVariantAttributesToPerProduct($variant, $product, $platformVariant['attributes']);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to sync variant', [
                    'product_id' => $product->id,
                    'external_id' => $platformVariant['external_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function syncVariantStock(ProductVariant $variant, int $quantity): void
    {
        try {
            $warehouse = $variant->product?->store?->getPrimaryWarehouse();

            if ($warehouse === null) {
                throw new InvalidArgumentException('No primary warehouse configured for variant stock sync.');
            }

            Stock::updateOrCreate(
                [
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                ],
                ['quantity' => $quantity],
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to sync variant stock', [
                'variant_id' => $variant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function syncVariantAttributesToPerProduct(ProductVariant $variant, Product $product, array $attributes): void
    {
        try {
            $valueIds = [];

            foreach ($attributes as $attrName => $attrValue) {
                $attribute = ProductAttribute::findOrCreateForProduct($product->id, (string) $attrName);
                $values = is_array($attrValue) ? $attrValue : [(string) $attrValue];

                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }

                    $valueIds[] = ProductAttributeValue::findOrCreateForAttribute($attribute->id, $value)->id;
                }
            }

            if ($valueIds !== []) {
                $variant->attributeValues()->sync($valueIds);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to sync variant attributes to per-product tables', [
                'variant_id' => $variant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getOrCreateWarehouse(Store $store): Warehouse
    {
        $warehouse = $store->getDefaultWarehouse();

        if ($warehouse === null) {
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
