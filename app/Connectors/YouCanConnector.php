<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Exceptions\ConnectorException;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YouCan Shop REST API connector.
 * Endpoints follow common REST patterns — verify against the live YouCan merchant docs.
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

    public function client(): PendingRequest
    {
        return Http::withToken((string) $this->connection->access_token)
            ->baseUrl($this->getBaseUrl())
            ->timeout(30)
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

    public function authenticate(): bool
    {
        try {
            $response = $this->guard($this->client()->get('/api/store'));

            return $response->successful();
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function getProducts(int $page = 1, int $perPage = 50): array
    {
        try {
            $response = $this->guard(
                $this->client()->get('/api/products', ['page' => $page, 'limit' => $perPage])
            );

            $response->throw();

            $body     = $response->json();
            $products = $body['data'] ?? $body ?? [];

            return array_map(
                fn (array $p) => $this->normalizeProduct($this->parseProduct($p)),
                (array) $products,
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function getOrders(int $page = 1, int $perPage = 50, ?Carbon $since = null): array
    {
        try {
            $params = ['page' => $page, 'limit' => $perPage];

            if ($since !== null) {
                $params['from'] = $since->timestamp;
            }

            $response = $this->guard($this->client()->get('/api/orders', $params));
            $response->throw();

            $body   = $response->json();
            $orders = $body['data'] ?? $body ?? [];

            return array_map(
                fn (array $o) => $this->normalizeOrder($this->parseOrder($o)),
                (array) $orders,
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    protected function parseProduct(array $product): array
    {
        $variants = array_map(function (array $v) {
            return [
                'external_id'   => (string) $v['id'],
                'name'          => $v['name'] ?? '',
                'sku'           => $v['reference'] ?? $v['sku'] ?? null,
                'price'         => $this->parsePrice($v['price'] ?? 0),
                'compare_price' => $this->parsePrice($v['compare_price'] ?? 0) ?: null,
                'attributes'    => $v['options'] ?? [],
                'images'        => [],
            ];
        }, $product['variants'] ?? []);

        return [
            'platform_id'   => (string) $product['id'],
            'name'          => $product['name'] ?? '',
            'description'   => strip_tags($product['description'] ?? ''),
            'sku'           => $product['reference'] ?? $product['sku'] ?? null,
            'type'          => !empty($product['variants']) ? 'variable' : 'simple',
            'price'         => $this->parsePrice($product['price'] ?? 0),
            'stock'         => isset($product['quantity']) ? (int) $product['quantity'] : null,
            'status'        => $this->parseStatus($product),
            'image_url'     => $product['thumbnail'] ?? $product['image'] ?? null,
            'images'        => (array) ($product['images'] ?? []),
            'variants'      => $variants,
            'platform_data' => $product,
        ];
    }

    protected function parseOrder(array $order): array
    {
        $customer     = $order['customer'] ?? [];
        $customerName = $customer['fullname']
            ?? $customer['name']
            ?? trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

        $items = array_map(fn (array $item) => [
            'external_id' => (string) ($item['id'] ?? ''),
            'product_id'  => (string) ($item['product_id'] ?? ''),
            'variant_id'  => (string) ($item['variant_id'] ?? ''),
            'name'        => $item['name'] ?? '',
            'sku'         => $item['sku'] ?? null,
            'quantity'    => (int) ($item['quantity'] ?? 1),
            'price'       => $this->parsePrice($item['price'] ?? 0),
            'subtotal'    => $this->parsePrice($item['subtotal'] ?? 0),
        ], $order['items'] ?? $order['products'] ?? []);

        return [
            'platform_id'    => (string) $order['id'],
            'number'         => (string) ($order['reference'] ?? $order['id']),
            'status'         => (string) ($order['status'] ?? 'unknown'),
            'total'          => $this->parsePrice($order['total_price'] ?? 0),
            'currency'       => $order['currency'] ?? 'MAD',
            'customer_name'  => $customerName !== '' ? $customerName : 'Unknown',
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $customer['phone'] ?? null,
            'items'          => $items,
            'created_at'     => $order['created_at'] ?? now()->toIso8601String(),
            'platform_data'  => $order,
        ];
    }

    /**
     * Fetch all variants for a single YouCan product.
     * Tries the dedicated variants endpoint first; falls back to the product endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductVariants(string $productId, int $page = 1, int $perPage = 100): array
    {
        try {
            $response = $this->client()->get("/api/products/{$productId}/variants", [
                'page'  => $page,
                'limit' => $perPage,
            ]);

            if ($response->successful()) {
                $body     = $response->json();
                $variants = $body['data'] ?? $body ?? [];

                return array_map(
                    fn (array $v) => $this->parseVariantData($v),
                    (array) $variants,
                );
            }

            // Fallback: pull variants from the product endpoint
            $productResponse = $this->guard(
                $this->client()->get("/api/products/{$productId}")
            );

            $productResponse->throw();

            $product  = $productResponse->json()['data'] ?? $productResponse->json() ?? [];
            $variants = $product['variants'] ?? [];

            return array_map(fn (array $v) => $this->parseVariantData($v), $variants);
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Normalize a raw YouCan variant payload.
     *
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function parseVariantData(array $variant): array
    {
        $options = $variant['options'] ?? [];
        $attributes = [];

        if (is_array($options)) {
            foreach ($options as $key => $value) {
                if (is_string($key) && $value !== '') {
                    $attributes[$key] = $value;
                } elseif (is_array($value) && isset($value['name'], $value['value'])) {
                    $attributes[$value['name']] = $value['value'];
                }
            }
        }

        return [
            'external_id'   => (string) ($variant['id'] ?? ''),
            'name'          => (string) ($variant['name'] ?? ($variant['reference'] ?? 'Variant')),
            'sku'           => (string) ($variant['reference'] ?? $variant['sku'] ?? ''),
            'price'         => $this->parsePrice($variant['price'] ?? 0),
            'cost'          => 0,
            'compare_price' => $this->parsePrice($variant['compare_price'] ?? 0),
            'stock'         => isset($variant['quantity']) ? (int) $variant['quantity'] : null,
            'attributes'    => $attributes,
        ];
    }

    /**
     * YouCan may return prices in centimes (15000 = 150.00 MAD).
     * Values >= 10000 are treated as centimes — verify against live API.
     */
    private function parsePrice(mixed $value): float
    {
        $price = (float) $value;

        return $price >= 10_000 ? $price / 100 : $price;
    }

    private function parseStatus(array $product): string
    {
        if (isset($product['status'])) {
            return (string) $product['status'];
        }

        return isset($product['is_active']) && $product['is_active'] ? 'active' : 'inactive';
    }

    /**
     * Push product changes to YouCan via PUT /api/products/{id}.
     *
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function createProduct(Product $product): array
    {
        try {
            $payload = [
                'name'        => $product->name,
                'description' => $product->description ?? '',
                'price'       => (int) round($product->price * 100),
                'reference'   => $product->sku ?? '',
                'status'      => $product->status === 'active' ? 1 : 0,
            ];

            $response = $this->guard($this->client()->post('/api/products', $payload));
            $response->throw();

            $data = $response->json();
            $id   = $data['data']['id'] ?? $data['id'] ?? '';

            return [
                'success'     => true,
                'external_id' => (string) $id,
                'message'     => 'Product created on YouCan',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan createProduct failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Create a variable product then POST each variant individually to /api/products/{id}/variants.
     *
     * @param  array{type: string, name: string, sku: string, price: float, description: string, status: string, attributes: array<string, list<string>>, variants: list<array{local_id: string, name: string, sku: string, price: float, attributes: array<string,mixed>, stock: int}>}  $productData
     * @return array{success: bool, external_id: string, variant_ids: array<string,string>, message: string, error: ?string}
     */
    public function createVariableProduct(array $productData): array
    {
        try {
            $response = $this->guard($this->client()->post('/api/products', [
                'name'        => $productData['name'],
                'description' => $productData['description'],
                'price'       => (int) round($productData['price'] * 100),
                'reference'   => $productData['sku'],
                'status'      => $productData['status'] === 'active' ? 1 : 0,
            ]));

            $response->throw();

            $data      = $response->json();
            $productId = (string) ($data['data']['id'] ?? $data['id'] ?? '');

            if ($productId === '') {
                return ['success' => false, 'external_id' => '', 'variant_ids' => [], 'message' => 'YouCan returned no product ID', 'error' => 'no_product_id'];
            }

            $variantIds     = [];
            $failedVariants = [];

            foreach ($productData['variants'] as $variant) {
                $attrs = $variant['attributes'];

                try {
                    $varResponse = $this->guard(
                        $this->client()->post("/api/products/{$productId}/variants", [
                            'name'      => $variant['name'],
                            'reference' => $variant['sku'],
                            'price'     => (int) round($variant['price'] * 100),
                            'quantity'  => $variant['stock'],
                            'options'   => collect($attrs)->map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v)->toArray(),
                        ])
                    );

                    $varResponse->throw();
                    $varData                             = $varResponse->json();
                    $variantIds[$variant['local_id']]   = (string) ($varData['data']['id'] ?? $varData['id'] ?? '');
                } catch (\Throwable $e) {
                    $failedVariants[] = $variant['name'];
                    Log::warning('YouCan: failed to create variant', ['variant' => $variant['name'], 'error' => $e->getMessage()]);
                }
            }

            $variantCount = count($variantIds);
            $message      = empty($failedVariants)
                ? "Product created on YouCan with {$variantCount} variant(s)"
                : "Product created, {$variantCount} variant(s) added, " . count($failedVariants) . ' failed';

            return [
                'success'     => true,
                'external_id' => $productId,
                'variant_ids' => $variantIds,
                'message'     => $message,
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan createVariableProduct failed', ['error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function pushProduct(Product $product): array
    {
        if (empty($product->external_id)) {
            return ['success' => false, 'external_id' => '', 'message' => 'Product has no external_id', 'error' => 'missing_external_id'];
        }

        try {
            $payload = [
                'name'        => $product->name,
                'description' => $product->description ?? '',
                'price'       => (int) round($product->price * 100),
                'reference'   => $product->sku ?? '',
                'status'      => $product->status === 'active' ? 1 : 0,
                'type'        => $product->type === 'variable' ? 'variable' : 'simple',
            ];

            $response = $this->guard(
                $this->client()->put("/api/products/{$product->external_id}", $payload)
            );

            $response->throw();

            $data = $response->json();
            $id   = $data['data']['id'] ?? $data['id'] ?? $product->external_id;

            return [
                'success'     => true,
                'external_id' => (string) $id,
                'message'     => 'Product pushed to YouCan successfully',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan pushProduct failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Create a new variant on YouCan via POST /api/products/{product_id}/variants.
     * Returns the platform-assigned variant ID in external_id.
     *
     * @param  string|null  $productExternalId  Platform-specific product ID (overrides model field)
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function createVariant(ProductVariant $variant, ?string $productExternalId = null): array
    {
        $product  = $variant->product;
        $parentId = $productExternalId ?? $product?->external_id;

        if (empty($parentId)) {
            return ['success' => false, 'external_id' => '', 'message' => 'Parent product has no external_id', 'error' => 'missing_product_external_id'];
        }

        try {
            $attrs = $variant->getAttribute('attributes') ?? [];

            $payload = [
                'name'      => $variant->getDisplayName(),
                'reference' => $variant->sku ?? '',
                'price'     => (int) round($variant->price * 100),
                'quantity'  => 0,
                'options'   => collect($attrs)->map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v)->toArray(),
            ];

            $response = $this->guard(
                $this->client()->post("/api/products/{$parentId}/variants", $payload)
            );

            $response->throw();

            $data = $response->json();
            $id   = $data['data']['id'] ?? $data['id'] ?? '';

            return [
                'success'     => true,
                'external_id' => (string) $id,
                'message'     => 'Variant created on YouCan',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan createVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Push variant changes to YouCan via PUT /api/products/{product_id}/variants/{id}.
     *
     * @param  string|null  $productExternalId  Platform-specific product ID (overrides model field)
     * @param  string|null  $variantExternalId  Platform-specific variant ID (overrides model field)
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function pushProductVariant(ProductVariant $variant, ?string $productExternalId = null, ?string $variantExternalId = null): array
    {
        $product  = $variant->product;
        $parentId = $productExternalId ?? $product?->external_id;
        $vid      = $variantExternalId ?? $variant->external_id;

        if (empty($vid)) {
            return ['success' => false, 'external_id' => '', 'message' => 'Variant has no external_id', 'error' => 'missing_external_id'];
        }

        if (empty($parentId)) {
            return ['success' => false, 'external_id' => '', 'message' => 'Parent product has no external_id', 'error' => 'missing_product_external_id'];
        }

        try {
            $attrs = $variant->getAttribute('attributes') ?? [];

            $payload = [
                'name'      => $variant->getDisplayName(),
                'reference' => $variant->sku ?? '',
                'price'     => (int) round($variant->price * 100),
                'options'   => collect($attrs)->map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v)->toArray(),
            ];

            $response = $this->guard(
                $this->client()->put("/api/products/{$parentId}/variants/{$vid}", $payload)
            );

            $response->throw();

            $data = $response->json();
            $id   = $data['data']['id'] ?? $data['id'] ?? $vid;

            return [
                'success'     => true,
                'external_id' => (string) $id,
                'message'     => 'Variant pushed to YouCan successfully',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan pushProductVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Push stock quantity to YouCan via PUT /api/products/{id}.
     *
     * @return array{success: bool, message: string, error: ?string}
     */
    public function pushStock(Product $product, int $quantity): array
    {
        if (empty($product->external_id)) {
            return ['success' => false, 'message' => 'Product has no external_id', 'error' => 'missing_external_id'];
        }

        try {
            $response = $this->guard(
                $this->client()->put("/api/products/{$product->external_id}", ['quantity' => $quantity])
            );

            $response->throw();

            return [
                'success' => true,
                'message' => "Stock set to {$quantity} on YouCan",
                'error'   => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan pushStock failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Delete a variant from YouCan via DELETE /api/products/{product_id}/variants/{id}.
     *
     * @return array{success: bool, message: string, error: ?string}
     */
    public function deleteVariant(ProductVariant $variant, ?string $productExternalId = null, ?string $variantExternalId = null): array
    {
        $product  = $variant->product;
        $parentId = $productExternalId ?? $product?->external_id;
        $vid      = $variantExternalId ?? $variant->external_id;

        if (empty($vid)) {
            return ['success' => false, 'message' => 'Variant has no external_id', 'error' => 'missing_external_id'];
        }

        if (empty($parentId)) {
            return ['success' => false, 'message' => 'Parent product has no external_id', 'error' => 'missing_product_external_id'];
        }

        try {
            $response = $this->guard(
                $this->client()->delete("/api/products/{$parentId}/variants/{$vid}")
            );

            $response->throw();

            return ['success' => true, 'message' => 'Variant deleted from YouCan', 'error' => null];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('YouCan deleteVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }
}
