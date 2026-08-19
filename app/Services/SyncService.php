<?php

namespace App\Services;

use App\Factories\ConnectorFactory;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use App\Services\Sync\ProductSyncService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyncService
{
    protected ProductSyncService $productService;
    protected OrderSyncService $orderService;

    public function __construct(
        ProductSyncService $productService,
        OrderSyncService $orderService
    ) {
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
     * Sync orders from a specific platform.
     *
     * Resolves the store's connection for the platform and delegates to
     * OrderSyncService, which pages through the orders and records a SyncLog.
     */
    public function syncOrders(Store $store, string $platform): SyncLog
    {
        Log::info("Starting order sync", [
            'store' => $store->id,
            'platform' => $platform,
        ]);

        $connection = $store->connections()->where('platform', $platform)->first();

        if ($connection === null) {
            throw new RuntimeException("No {$platform} connection found for store {$store->id}");
        }

        try {
            return $this->orderService->syncFromPlatform($store, $connection);
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
     * Test that a connection's credentials are valid.
     */
    public function testConnection(PlatformConnection $connection): bool
    {
        try {
            return ConnectorFactory::make($connection)->authenticate();
        } catch (\Exception $e) {
            Log::error("Connection test failed", [
                'platform' => $connection->platform,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}