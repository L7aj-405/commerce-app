<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Contracts\PlatformConnectorInterface;
use App\Exceptions\ConnectorException;
use App\Models\PlatformConnection;
use Exception;
use Illuminate\Support\Facades\Log;

abstract class BaseConnector implements PlatformConnectorInterface
{
    public function __construct(
        protected readonly PlatformConnection $connection,
    ) {}

    public function getPlatform(): string
    {
        return $this->connection->platform;
    }

    protected function normalizeProduct(array $raw): array
    {
        return [
            'platform_id'   => (string) ($raw['platform_id'] ?? ''),
            'name'          => (string) ($raw['name'] ?? ''),
            'sku'           => isset($raw['sku']) ? (string) $raw['sku'] : null,
            'price'         => (float) ($raw['price'] ?? 0.0),
            'stock'         => isset($raw['stock']) ? (int) $raw['stock'] : null,
            'status'        => (string) ($raw['status'] ?? 'unknown'),
            'image_url'     => isset($raw['image_url']) ? (string) $raw['image_url'] : null,
            'platform_data' => (array) ($raw['platform_data'] ?? $raw),
        ];
    }

    protected function normalizeOrder(array $raw): array
    {
        return [
            'platform_id'    => (string) ($raw['platform_id'] ?? ''),
            'number'         => (string) ($raw['number'] ?? ''),
            'status'         => (string) ($raw['status'] ?? 'unknown'),
            'total'          => (float) ($raw['total'] ?? 0.0),
            'currency'       => (string) ($raw['currency'] ?? 'MAD'),
            'customer_name'  => (string) ($raw['customer_name'] ?? ''),
            'customer_email' => isset($raw['customer_email']) ? (string) $raw['customer_email'] : null,
            'customer_phone' => isset($raw['customer_phone']) ? (string) $raw['customer_phone'] : null,
            'items'          => (array) ($raw['items'] ?? []),
            'created_at'     => (string) ($raw['created_at'] ?? now()->toIso8601String()),
            'platform_data'  => (array) ($raw['platform_data'] ?? $raw),
        ];
    }

    protected function handleRequestException(Exception $e): never
    {
        $platform = $this->getPlatform();

        Log::error("Connector error [{$platform}]", [
            'message'    => $e->getMessage(),
            'connection' => $this->connection->id,
            'exception'  => $e,
        ]);

        throw ConnectorException::connectionFailed($platform, $e->getMessage());
    }
}
