<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Contracts\PlatformConnectorInterface;
use App\Models\PlatformConnection;
use InvalidArgumentException;

class ConnectorFactory
{
    public static function make(PlatformConnection $connection): PlatformConnectorInterface
    {
        return match ($connection->platform) {
            PlatformConnection::PLATFORM_WOOCOMMERCE => new WooCommerceConnector($connection),
            PlatformConnection::PLATFORM_SHOPIFY     => new ShopifyConnector($connection),
            PlatformConnection::PLATFORM_YOUCAN      => new YouCanConnector($connection),
            default => throw new InvalidArgumentException(
                "Unsupported platform: {$connection->platform}"
            ),
        };
    }
}
