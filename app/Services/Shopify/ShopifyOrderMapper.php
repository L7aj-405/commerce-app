<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Connectors\ShopifyConnector;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Services\Sync\OrderSyncService;

class ShopifyOrderMapper
{
    public function __construct(private readonly OrderSyncService $orders) {}

    /**
     * Map a Shopify orders/create|updated webhook payload onto the canonical
     * Order. Reuses OrderSyncService::saveOrder() — idempotent on
     * (platform_connection_id, platform_order_id), never resets an order the
     * confirmation desk already advanced, and allocates no inventory.
     */
    public function map(array $payload, PlatformConnection $connection): ?Order
    {
        $normalized = (new ShopifyConnector($connection))->mapWebhookOrder($payload);

        $order = $this->orders->saveOrder($normalized, $connection);

        if ($order !== null) {
            $this->orders->createOrderItems($order, $normalized['items'] ?? []);
        }

        return $order;
    }
}
