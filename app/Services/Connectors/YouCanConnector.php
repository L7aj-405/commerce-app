<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Exceptions\ConnectorException;
use App\Models\PlatformConnection;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * YouCan API endpoints based on common REST patterns.
 * Verify against YouCan merchant API docs and adjust if needed.
 */
class YouCanConnector extends BaseConnector
{
    public function __construct(PlatformConnection $connection)
    {
        if ($connection->platform !== PlatformConnection::PLATFORM_YOUCAN) {
            throw new \InvalidArgumentException(
                "YouCanConnector requires platform 'youcan', got '{$connection->platform}'"
            );
        }

        parent::__construct($connection);
    }

    public function getBaseUrl(): string
    {
        return rtrim((string) $this->connection->api_url, '/');
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) $this->connection->access_token)
            ->baseUrl($this->getBaseUrl())
            ->timeout(15)
            ->acceptJson();
    }

    private function guard(Response $response): Response
    {
        if ($response->status() === 401) {
            throw ConnectorException::authFailed($this->getPlatform());
        }

        if ($response->status() === 429) {
            throw ConnectorException::rateLimited($this->getPlatform());
        }

        return $response;
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->guard(
                $this->client()->get('/api/store')
            );

            return $response->successful();
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function fetchProducts(int $page = 1, int $perPage = 50): array
    {
        try {
            $response = $this->guard(
                $this->client()->get('/api/products', [
                    'page'  => $page,
                    'limit' => $perPage,
                ])
            );

            $response->throw();

            $body     = $response->json();
            $products = $body['data'] ?? $body ?? [];

            return array_map(
                fn (array $product) => $this->normalizeProduct([
                    'platform_id'   => (string) $product['id'],
                    'name'          => $product['name'] ?? '',
                    'sku'           => $product['reference'] ?? $product['sku'] ?? null,
                    'price'         => $this->parsePrice($product['price'] ?? 0),
                    'stock'         => isset($product['quantity']) ? (int) $product['quantity'] : null,
                    'status'        => $this->parseStatus($product),
                    'image_url'     => $product['thumbnail'] ?? $product['image'] ?? null,
                    'platform_data' => $product,
                ]),
                $products,
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function fetchOrders(int $page = 1, int $perPage = 50, ?Carbon $since = null): array
    {
        try {
            $params = [
                'page'  => $page,
                'limit' => $perPage,
            ];

            if ($since !== null) {
                $params['from'] = $since->timestamp;
            }

            $response = $this->guard(
                $this->client()->get('/api/orders', $params)
            );

            $response->throw();

            $body   = $response->json();
            $orders = $body['data'] ?? $body ?? [];

            return array_map(
                fn (array $order) => $this->normalizeOrder($this->mapOrder($order)),
                $orders,
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function fetchOrder(string $platformOrderId): ?array
    {
        try {
            $response = $this->guard(
                $this->client()->get("/api/orders/{$platformOrderId}")
            );

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();

            $body  = $response->json();
            $order = $body['data'] ?? $body;

            return $order !== null ? $this->normalizeOrder($this->mapOrder($order)) : null;
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function updateOrderStatus(string $platformOrderId, string $status): bool
    {
        try {
            $response = $this->guard(
                $this->client()->put("/api/orders/{$platformOrderId}", ['status' => $status])
            );

            return $response->successful();
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    private function mapOrder(array $order): array
    {
        $customer    = $order['customer'] ?? [];
        $customerName = $customer['fullname']
            ?? $customer['name']
            ?? trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

        return [
            'platform_id'    => (string) $order['id'],
            'number'         => (string) ($order['reference'] ?? $order['id']),
            'status'         => (string) ($order['status'] ?? 'unknown'),
            'total'          => $this->parsePrice($order['total_price'] ?? 0),
            'currency'       => $order['currency'] ?? 'MAD',
            'customer_name'  => $customerName !== '' ? $customerName : 'Unknown',
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phone'] ?? null,
            'items'          => $order['items'] ?? $order['products'] ?? [],
            'created_at'     => $order['created_at'] ?? now()->toIso8601String(),
            'platform_data'  => $order,
        ];
    }

    private function parsePrice(mixed $value): float
    {
        $price = (float) $value;

        // YouCan may return prices in centimes (e.g. 15000 = 150.00 MAD)
        // Treat values >= 10000 that look like centimes as such.
        // Verify against live API response and adjust threshold if needed.
        return $price >= 10000 ? $price / 100 : $price;
    }

    private function parseStatus(array $product): string
    {
        if (isset($product['status'])) {
            return (string) $product['status'];
        }

        if (isset($product['is_active'])) {
            return $product['is_active'] ? 'active' : 'inactive';
        }

        return 'unknown';
    }
}
