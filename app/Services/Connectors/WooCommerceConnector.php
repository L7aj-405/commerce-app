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

class WooCommerceConnector extends BaseConnector
{
    public function __construct(PlatformConnection $connection)
    {
        if ($connection->platform !== PlatformConnection::PLATFORM_WOOCOMMERCE) {
            throw new \InvalidArgumentException(
                "WooCommerceConnector requires platform 'woocommerce', got '{$connection->platform}'"
            );
        }

        parent::__construct($connection);
    }

    public function getBaseUrl(): string
    {
        return rtrim((string) $this->connection->api_url, '/') . '/wp-json/wc/v3';
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) $this->connection->consumer_key,
            (string) $this->connection->consumer_secret,
        )
            ->baseUrl($this->getBaseUrl())
            ->timeout(60)
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
                $this->client()->get('/system_status')
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
                $this->client()->get('/products', [
                    'page'     => $page,
                    'per_page' => $perPage,
                    'status'   => 'publish',
                ])
            );

            $response->throw();

            return array_map(
                fn (array $product) => $this->normalizeProduct([
                    'platform_id'   => (string) $product['id'],
                    'name'          => $product['name'] ?? '',
                    'sku'           => $product['sku'] ?? null,
                    'price'         => (float) ($product['price'] ?? 0),
                    'stock'         => isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : null,
                    'status'        => $product['status'] ?? 'unknown',
                    'image_url'     => $product['images'][0]['src'] ?? null,
                    'platform_data' => $product,
                ]),
                $response->json() ?? [],
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
                'page'     => $page,
                'per_page' => $perPage,
                'orderby'  => 'date',
                'order'    => 'desc',
            ];

            if ($since !== null) {
                $params['after'] = $since->toIso8601String();
            }

            $response = $this->guard(
                $this->client()->get('/orders', $params)
            );

            $response->throw();

            return array_map(
                fn (array $order) => $this->normalizeOrder($this->mapOrder($order)),
                $response->json() ?? [],
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
                $this->client()->get("/orders/{$platformOrderId}")
            );

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();

            return $this->normalizeOrder($this->mapOrder($response->json()));
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
                $this->client()->put("/orders/{$platformOrderId}", ['status' => $status])
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
        $billing     = $order['billing'] ?? [];
        $firstName   = $billing['first_name'] ?? '';
        $lastName    = $billing['last_name'] ?? '';
        $customerName = trim("{$firstName} {$lastName}");

        return [
            'platform_id'    => (string) $order['id'],
            'number'         => (string) ($order['number'] ?? $order['id']),
            'status'         => $order['status'] ?? 'unknown',
            'total'          => (float) ($order['total'] ?? 0),
            'currency'       => $order['currency'] ?? 'MAD',
            'customer_name'  => $customerName !== '' ? $customerName : 'Unknown',
            'customer_email' => $billing['email'] ?? null,
            'customer_phone' => $billing['phone'] ?? null,
            'items'          => $order['line_items'] ?? [],
            'created_at'     => $order['date_created'] ?? now()->toIso8601String(),
            'platform_data'  => $order,
        ];
    }
}
