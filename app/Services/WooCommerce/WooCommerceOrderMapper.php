<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Connectors\WooCommerceConnector;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Services\Sync\OrderSyncService;

class WooCommerceOrderMapper
{
    public function __construct(private readonly OrderSyncService $orders) {}

    /**
     * Map a WooCommerce order.created|updated webhook payload onto the
     * canonical Order. Reuses OrderSyncService::saveOrder() — the exact
     * same idempotent method the manual/queued sync and the Shopify webhook
     * both use — so webhook and sync can never disagree about what "this
     * order already exists" means.
     */
    public function map(array $payload, PlatformConnection $connection): ?Order
    {
        $normalized = (new WooCommerceConnector($connection))->mapWebhookOrder($payload);

        $order = $this->orders->saveOrder($normalized, $connection);

        if ($order !== null) {
            $this->orders->createOrderItems($order, $normalized['items'] ?? []);
        }

        return $order;
    }
}
