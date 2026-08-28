<?php

declare(strict_types=1);

namespace App\Factories;

use App\Connectors\Delivery\OzonExpressConnector;
use App\Connectors\Delivery\SenditConnector;
use App\Contracts\DeliveryProviderConnectorInterface;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProvider;
use InvalidArgumentException;

/**
 * Instantiates the correct delivery-provider connector for a connection —
 * mirrors ConnectorFactory (the commerce-platform equivalent). Lets
 * provider-agnostic services (ShipmentTrackingService, the tracking job)
 * resolve the right connector without hardcoding Ozon.
 */
class DeliveryConnectorFactory
{
    /** @throws InvalidArgumentException for a provider with no connector */
    public static function make(DeliveryConnection $connection): DeliveryProviderConnectorInterface
    {
        return match ($connection->provider_code) {
            DeliveryProvider::OZON => new OzonExpressConnector($connection),
            DeliveryProvider::SENDIT => new SenditConnector($connection),
            default => throw new InvalidArgumentException(
                "Unsupported delivery provider: {$connection->provider_code}"
            ),
        };
    }
}
