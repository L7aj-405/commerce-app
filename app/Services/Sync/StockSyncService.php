<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Connectors\YouCanConnector;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    /**
     * Sync product stock quantities from a platform into local Stock records.
     *
     * @return array{updated: int, failed: int}
     */
    public function syncStockFromPlatform(Store $store, string $platform): array
    {
        $connection = $store->connections()
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();

        if ($connection === null) {
            Log::warning('No active connection for stock sync', [
                'store'    => $store->id,
                'platform' => $platform,
            ]);
            return ['updated' => 0, 'failed' => 0];
        }

        $connector = match ($platform) {
            'woocommerce' => new WooCommerceConnector($connection),
            'shopify'     => new ShopifyConnector($connection),
            'youcan'      => new YouCanConnector($connection),
            default       => throw new \InvalidArgumentException("Unknown platform: $platform"),
        };

        $warehouse = $this->getOrCreateWarehouse($store);

        $updated = 0;
        $failed  = 0;
        $page    = 1;
        $perPage = 50;

        while (true) {
            try {
                $platformProducts = $connector->getProducts($page, $perPage);
            } catch (\Exception $e) {
                Log::error('Failed to fetch products for stock sync', [
                    'store'    => $store->id,
                    'platform' => $platform,
                    'page'     => $page,
                    'error'    => $e->getMessage(),
                ]);
                break;
            }

            if (empty($platformProducts)) {
                break;
            }

            foreach ($platformProducts as $platformProduct) {
                try {
                    $result = $this->updateStockForProduct(
                        $platformProduct,
                        $store,
                        $warehouse,
                        $platform
                    );

                    if ($result) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    Log::warning('Failed to update stock for product', [
                        'external_id' => $platformProduct['external_id'] ?? 'unknown',
                        'store'       => $store->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            if (count($platformProducts) < $perPage) {
                break;
            }

            $page++;
        }

        Log::info('Stock sync completed', [
            'store'    => $store->id,
            'platform' => $platform,
            'updated'  => $updated,
            'failed'   => $failed,
        ]);

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Update the Stock record for a single product from platform data.
     */
    private function updateStockForProduct(
        array $platformProduct,
        Store $store,
        Warehouse $warehouse,
        string $platform
    ): bool {
        $externalId = (string) ($platformProduct['external_id'] ?? '');

        $product = Product::where('external_id', $externalId)
            ->where('store_id', $store->id)
            ->first();

        if ($product === null) {
            return false;
        }

        $newQty = (int) ($platformProduct['stock_quantity'] ?? $platformProduct['quantity'] ?? 0);

        DB::transaction(function () use ($product, $warehouse, $newQty, $platform): void {
            $stock = Stock::firstOrCreate(
                [
                    'product_id'   => $product->id,
                    'warehouse_id' => $warehouse->id,
                ],
                ['quantity' => 0]
            );

            $previousQty = $stock->quantity;

            if ($previousQty === $newQty) {
                return;
            }

            $stock->update(['quantity' => $newQty]);

            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id'   => $product->id,
                'user_id'      => $warehouse->user_id,
                'type'         => 'platform_sync',
                'quantity'     => $newQty - $previousQty,
                'notes'        => "Stock updated from {$platform} (was {$previousQty}, now {$newQty})",
            ]);
        });

        return true;
    }

    private function getOrCreateWarehouse(Store $store): Warehouse
    {
        return $store->getPrimaryWarehouse();
    }
}
