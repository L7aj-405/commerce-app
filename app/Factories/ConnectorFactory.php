<?php

declare(strict_types=1);

namespace App\Factories;

use App\Connectors\BaseConnector;
use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Connectors\YouCanConnector;
use App\Models\PlatformConnection;
use InvalidArgumentException;

class ConnectorFactory
{
    /**
     * Instantiate the correct connector for the given platform connection.
     *
     * @throws InvalidArgumentException for unsupported platforms
     */
    public static function make(PlatformConnection $connection): BaseConnector
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
