<?php

namespace App\Services;

use App\Factories\ConnectorFactory;
use App\Models\Store;
use App\Services\Sync\ProductSyncService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Log;

class SyncService
{
    protected ConnectorFactory $factory;
    protected ProductSyncService $productService;
    protected OrderSyncService $orderService;

    public function __construct(
        ConnectorFactory $factory,
        ProductSyncService $productService,
        OrderSyncService $orderService
    ) {
        $this->factory = $factory;
        $this->productService = $productService;
        $this->orderService = $orderService;
    }

    /**
     * Sync products from a specific platform
     */
    public function syncProducts(Store $store, string $platform): array
    {
        Log::info("Starting product sync", [
            'store' => $store->id,
            'platform' => $platform,
        ]);

        try {
            return $this->productService->syncFromPlatform($store, $platform);
        } catch (\Exception $e) {
            Log::error("Product sync failed", [
                'store' => $store->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync orders from a specific platform
     */
    public function syncOrders(Store $store, string $platform): array
    {
        Log::info("Starting order sync", [
            'store' => $store->id,
            'platform' => $platform,
        ]);

        try {
            return $this->orderService->syncFromPlatform($store, $platform);
        } catch (\Exception $e) {
            Log::error("Order sync failed", [
                'store' => $store->id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync all data from all platforms
     */
    public function syncAll(Store $store): array
    {
        $results = [
            'products' => [],
            'orders' => [],
        ];

        foreach ($store->connections as $connection) {
            try {
                $results['products'][$connection->platform] = $this->syncProducts($store, $connection->platform);
            } catch (\Exception $e) {
                Log::error("Product sync failed", ['error' => $e->getMessage()]);
            }

            try {
                $results['orders'][$connection->platform] = $this->syncOrders($store, $connection->platform);
            } catch (\Exception $e) {
                Log::error("Order sync failed", ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Test connection
     */
    public function testConnection($connection): bool
    {
        try {
            $connector = $this->factory->create($connection->platform);
            return $connector->authenticate();
        } catch (\Exception $e) {
            Log::error("Connection test failed", [
                'platform' => $connection->platform,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}