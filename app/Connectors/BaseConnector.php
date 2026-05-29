<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Exceptions\ConnectorException;
use App\Models\PlatformConnection;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

abstract class BaseConnector
{
    public function __construct(
        protected readonly PlatformConnection $connection,
    ) {}

    /** Verify credentials and return true if the connection is live. */
    abstract public function authenticate(): bool;

    /** Fetch a page of products from the platform, returning normalized arrays. */
    abstract public function getProducts(int $page = 1, int $perPage = 50): array;

    /** Fetch a page of orders from the platform, returning normalized arrays. */
    abstract public function getOrders(int $page = 1, int $perPage = 50, ?Carbon $since = null): array;

    /** Map raw platform product data to a shape ready for normalizeProduct(). */
    abstract protected function parseProduct(array $raw): array;

    /** Map raw platform order data to a shape ready for normalizeOrder(). */
    abstract protected function parseOrder(array $raw): array;

    abstract public function getBaseUrl(): string;

    public function getPlatform(): string
    {
        return $this->connection->platform;
    }

    protected function normalizeProduct(array $raw): array
    {
        return [
            'external_id'   => (string) ($raw['external_id'] ?? $raw['platform_id'] ?? ''),
            'platform_id'   => (string) ($raw['platform_id'] ?? $raw['external_id'] ?? ''),
            'platform'      => $this->getPlatform(),
            'name'          => (string) ($raw['name'] ?? ''),
            'description'   => (string) ($raw['description'] ?? ''),
            'sku'           => isset($raw['sku']) ? (string) $raw['sku'] : null,
            'type'          => (string) ($raw['type'] ?? 'simple'),
            'price'         => (float) ($raw['price'] ?? 0.0),
            'cost'          => isset($raw['cost']) ? (float) $raw['cost'] : null,
            'compare_price' => isset($raw['compare_price']) ? (float) $raw['compare_price'] : null,
            'stock'         => isset($raw['stock']) ? (int) $raw['stock'] : null,
            'status'        => (string) ($raw['status'] ?? 'unknown'),
            'featured_image'=> isset($raw['image_url']) ? (string) $raw['image_url'] : null,
            'images'        => (array) ($raw['images'] ?? []),
            'variants'      => (array) ($raw['variants'] ?? []),
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
        ]);

        throw ConnectorException::connectionFailed($platform, $e->getMessage());
    }
}
