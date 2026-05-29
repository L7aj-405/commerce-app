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
use Illuminate\Support\Facades\Log;

class ShopifyConnector extends BaseConnector
{
    public function __construct(PlatformConnection $connection)
    {
        if ($connection->platform !== PlatformConnection::PLATFORM_SHOPIFY) {
            throw new \InvalidArgumentException(
                "ShopifyConnector requires platform 'shopify', got '{$connection->platform}'"
            );
        }

        parent::__construct($connection);
    }

    public function getBaseUrl(): string
    {
        return 'https://' . $this->connection->shop_domain . '/admin/api/2024-01';
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
            $retryAfter = $response->header('Retry-After');

            throw new ConnectorException(
                message: "Shopify API rate limit exceeded",
                platform: $this->getPlatform(),
                context: ['retry_after' => $retryAfter],
            );
        }

        return $response;
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->guard(
                $this->client()->get('/shop.json')
            );

            return $response->successful() && array_key_exists('shop', $response->json() ?? []);
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
                $this->client()->get('/products.json', ['limit' => $perPage])
            );

            $response->throw();

            $products = $response->json('products') ?? [];

            return array_map(
                fn (array $product) => $this->normalizeProduct([
                    'platform_id'   => (string) $product['id'],
                    'name'          => $product['title'] ?? '',
                    'sku'           => $product['variants'][0]['sku'] ?? null,
                    'price'         => (float) ($product['variants'][0]['price'] ?? 0),
                    'stock'         => isset($product['variants'][0]['inventory_quantity'])
                        ? (int) $product['variants'][0]['inventory_quantity']
                        : null,
                    'status'        => $product['status'] ?? 'unknown',
                    'image_url'     => $product['images'][0]['src'] ?? null,
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
                'limit'  => $perPage,
                'status' => 'any',
            ];

            if ($since !== null) {
                $params['created_at_min'] = $since->toIso8601String();
            }

            $response = $this->guard(
                $this->client()->get('/orders.json', $params)
            );

            $response->throw();

            $orders = $response->json('orders') ?? [];

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
                $this->client()->get("/orders/{$platformOrderId}.json")
            );

            if ($response->status() === 404) {
                return null;
            }

            $response->throw();

            $order = $response->json('order');

            return $order !== null ? $this->normalizeOrder($this->mapOrder($order)) : null;
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function updateOrderStatus(string $platformOrderId, string $status): bool
    {
        Log::warning('ShopifyConnector::updateOrderStatus called — Shopify order status is managed via fulfillments, not direct updates', [
            'connection'       => $this->connection->id,
            'platform_order_id' => $platformOrderId,
            'requested_status' => $status,
        ]);

        return true;
    }

    private function mapOrder(array $order): array
    {
        $customer    = $order['customer'] ?? [];
        $shipping    = $order['shipping_address'] ?? [];
        $firstName   = $customer['first_name'] ?? '';
        $lastName    = $customer['last_name'] ?? '';
        $customerName = trim("{$firstName} {$lastName}");

        return [
            'platform_id'    => (string) $order['id'],
            'number'         => '#' . ($order['order_number'] ?? $order['id']),
            'status'         => $order['financial_status'] ?? 'unknown',
            'total'          => (float) ($order['current_total_price'] ?? 0),
            'currency'       => $order['currency'] ?? 'MAD',
            'customer_name'  => $customerName !== '' ? $customerName : 'Unknown',
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $shipping['phone'] ?? $customer['phone'] ?? null,
            'items'          => $order['line_items'] ?? [],
            'created_at'     => $order['created_at'] ?? now()->toIso8601String(),
            'platform_data'  => $order,
        ];
    }
}
