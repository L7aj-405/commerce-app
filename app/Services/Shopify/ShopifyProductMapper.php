<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Connectors\ShopifyConnector;
use App\Models\PlatformConnection;
use App\Services\Sync\ProductSyncService;

class ShopifyProductMapper
{
    public function __construct(private readonly ProductSyncService $products) {}

    /**
     * Map a Shopify products/create|update webhook payload onto the canonical
     * catalog. Reuses ProductSyncService::saveProduct() — the exact same
     * listing-first/external-id/SKU merge and store-scoping logic the regular
     * pull-sync already trusts.
     */
    public function map(array $payload, PlatformConnection $connection): string
    {
        $connection->loadMissing('store');

        $normalized = (new ShopifyConnector($connection))->mapWebhookProduct($payload);

        return $this->products->saveProduct($normalized, $connection->store, 'shopify', $connection);
    }
}
