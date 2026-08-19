<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Connectors\YouCanConnector;
use App\Enums\StockMovementType;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    /** @return array{updated: int, failed: int} */
    public function syncStockFromPlatform(Store $store, string $platform): array
    {
        $connection = $store->connections()
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();

        if ($connection === null) {
            return ['updated' => 0, 'failed' => 0];
        }

        $connector = match ($platform) {
            'woocommerce' => new WooCommerceConnector($connection),
            'shopify' => new ShopifyConnector($connection),
            'youcan' => new YouCanConnector($connection),
            default => throw new \InvalidArgumentException("Unknown platform: {$platform}"),
        };

        $warehouse = $this->getOrCreateWarehouse($store);
        $updated = 0;
        $failed = 0;
        $page = 1;
        $perPage = 50;
        $seenPages = [];

        while (true) {
            try {
                $platformProducts = $connector->getProducts($page, $perPage);
            } catch (\Throwable $e) {
                Log::error('Failed to fetch products for stock sync', [
                    'store' => $store->id,
                    'connection' => $connection->id,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            if (empty($platformProducts)) {
                break;
            }

            $fingerprint = hash('sha256', json_encode(array_map(
                static fn (array $product): string => (string) ($product['external_id'] ?? ''),
                $platformProducts,
            )) ?: '');

            if (isset($seenPages[$fingerprint])) {
                break;
            }
            $seenPages[$fingerprint] = true;

            foreach ($platformProducts as $platformProduct) {
                try {
                    if ($this->updateStockForProduct($platformProduct, $store, $warehouse, $connection)) {
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Failed to update stock for product', [
                        'external_id' => $platformProduct['external_id'] ?? 'unknown',
                        'store' => $store->id,
                        'connection' => $connection->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (count($platformProducts) < $perPage) {
                break;
            }

            $page++;
        }

        return compact('updated', 'failed');
    }

    private function updateStockForProduct(
        array $platformProduct,
        Store $store,
        Warehouse $warehouse,
        PlatformConnection $connection,
    ): bool {
        $externalId = trim((string) ($platformProduct['external_id'] ?? ''));
        if ($externalId === '') {
            return false;
        }

        $product = ProductChannelListing::query()
            ->where('platform_connection_id', $connection->id)
            ->where('external_product_id', $externalId)
            ->with('product')
            ->first()?->product;

        $product ??= Product::query()
            ->where('store_id', $store->id)
            ->where('platform', $connection->platform)
            ->where('external_id', $externalId)
            ->first();

        if ($product === null || $product->isVariable()) {
            return false;
        }

        $newQty = (int) ($platformProduct['stock_quantity'] ?? $platformProduct['stock'] ?? $platformProduct['quantity'] ?? 0);

        DB::transaction(function () use ($product, $warehouse, $newQty, $connection): void {
            $stock = Stock::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'variant_id' => null,
                ],
                ['quantity' => 0],
            );

            $previousQty = (int) $stock->quantity;
            if ($previousQty === $newQty) {
                return;
            }

            $stock->update(['quantity' => $newQty]);

            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'user_id' => $warehouse->user_id,
                'type' => StockMovementType::Adjustment->value,
                'quantity' => $newQty - $previousQty,
                'notes' => "Stock pulled from {$connection->platform} (was {$previousQty}, now {$newQty})",
            ]);
        });

        return true;
    }

    private function getOrCreateWarehouse(Store $store): Warehouse
    {
        return $store->getPrimaryWarehouse()
            ?? throw new \RuntimeException('No primary warehouse configured for stock sync.');
    }
}
