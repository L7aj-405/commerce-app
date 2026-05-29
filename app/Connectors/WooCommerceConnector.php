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

    public function client(): PendingRequest
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

    public function authenticate(): bool
    {
        try {
            $response = $this->guard($this->client()->get('/system_status'));

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
                $this->client()->get('/products', [
                    'page'     => $page,
                    'per_page' => $perPage,
                    'status'   => 'publish',
                ])
            );

            $response->throw();

            return array_map(
                fn (array $p) => $this->normalizeProduct($this->parseProduct($p)),
                $response->json() ?? [],
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
            $params = [
                'page'     => $page,
                'per_page' => $perPage,
                'orderby'  => 'date',
                'order'    => 'desc',
            ];

            if ($since !== null) {
                $params['after'] = $since->toIso8601String();
            }

            $response = $this->guard($this->client()->get('/orders', $params));
            $response->throw();

            return array_map(
                fn (array $o) => $this->normalizeOrder($this->parseOrder($o)),
                $response->json() ?? [],
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Parse WooCommerce product to normalized format
     */
    protected function parseProduct(array $product): array
    {
        $attributes = [];
        
        if (isset($product['attributes']) && is_array($product['attributes'])) {
            foreach ($product['attributes'] as $attr) {
                if (is_array($attr) && isset($attr['name']) && isset($attr['options'])) {
                    $options = $attr['options'];
                    
                    if (!is_array($options)) {
                        $options = [$options];
                    }
                    
                    $options = array_filter($options, fn($o) => !empty($o));
                    
                    if (!empty($options)) {
                        $attributes[$attr['name']] = implode(', ', $options);
                    }
                }
            }
        }
        
        $images = [];
        if (isset($product['images']) && is_array($product['images'])) {
            $images = array_map(fn($img) => $img['src'] ?? null, $product['images']);
            $images = array_filter($images);
        }
        
        $featuredImage = null;
        if (!empty($images)) {
            $featuredImage = reset($images);
        }
        
        return [
            'name'          => $product['name'] ?? 'Unnamed Product',
            'description'   => $product['description'] ?? '',
            'sku'           => $product['sku'] ?? '',
            'type'          => ($product['type'] === 'variable') ? 'variable' : 'simple',
            'price'         => (float) ($product['price'] ?? 0),
            'cost'          => 0,
            'compare_price' => (float) ($product['regular_price'] ?? 0),
            'status'        => ($product['status'] === 'publish') ? 'active' : 'draft',
            'images'        => array_values($images),
            'featured_image' => $featuredImage,
            'external_id'   => (string) ($product['id'] ?? ''),
            'stock'         => isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : null,
            'attributes'    => $attributes,
            'metadata'      => [
                'wc_id' => $product['id'] ?? null,
                'type'  => $product['type'] ?? 'simple',
            ],
        ];
    }

    /**
     * Fetch all variants for a single variable product.
     * Calls /products/{id}/variations — WooCommerce REST API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductVariants(string $productId, int $page = 1, int $perPage = 100): array
    {
        try {
            $response = $this->guard(
                $this->client()->get("/products/{$productId}/variations", [
                    'page'     => $page,
                    'per_page' => $perPage,
                ])
            );

            $response->throw();

            return array_map(
                fn (array $v) => $this->parseVariant($v),
                $response->json() ?? [],
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Parse a WooCommerce product variation into a normalized variant array.
     * WooCommerce variations have no `name` field — it is built from attributes.
     */
    protected function parseVariant(array $variant): array
    {
        $attributes = [];
        $nameParts  = [];

        if (isset($variant['attributes']) && is_array($variant['attributes'])) {
            foreach ($variant['attributes'] as $attr) {
                if (is_array($attr) && isset($attr['name'], $attr['option']) && $attr['option'] !== '') {
                    $attributes[$attr['name']] = $attr['option'];
                    $nameParts[]               = $attr['option'];
                }
            }
        }

        $name = !empty($nameParts)
            ? implode(' / ', $nameParts)
            : ($variant['sku'] ?: 'Variant');

        return [
            'external_id'   => (string) ($variant['id'] ?? ''),
            'name'          => $name,
            'sku'           => $variant['sku'] ?? '',
            'price'         => (float) ($variant['price'] ?? 0),
            'cost'          => 0,
            'compare_price' => (float) ($variant['regular_price'] ?? 0),
            'stock'         => isset($variant['stock_quantity']) ? (int) $variant['stock_quantity'] : null,
            'attributes'    => $attributes,
        ];
    }

    /**
     * Parse WooCommerce order
     */
    protected function parseOrder(array $order): array
    {
        $items = [];
        
        if (isset($order['line_items']) && is_array($order['line_items'])) {
            foreach ($order['line_items'] as $item) {
                $items[] = [
                    'product_id' => (string) ($item['product_id'] ?? ''),
                    'sku' => $item['sku'] ?? '',
                    'name' => $item['name'] ?? '',
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    'total' => (float) ($item['total'] ?? 0),
                    'external_id' => (string) ($item['id'] ?? ''),
                ];
            }
        }
        
        $billingAddress = $order['billing'] ?? [];
        
        $customerName = trim(
            ($billingAddress['first_name'] ?? '') . ' ' . 
            ($billingAddress['last_name'] ?? '')
        ) ?: 'Guest Customer';
        
        return [
            'external_id' => (string) ($order['id'] ?? ''),
            'order_number' => (string) ($order['number'] ?? ''),
            'customer_name' => $customerName,
            'customer_email' => $billingAddress['email'] ?? '',
            'customer_phone' => $billingAddress['phone'] ?? '',
            'status' => $this->mapOrderStatus($order['status'] ?? ''),
            'total' => (float) ($order['total'] ?? 0),
            'subtotal' => (float) ($order['subtotal'] ?? 0),
            'tax' => (float) ($order['total_tax'] ?? 0),
            'shipping' => (float) ($order['shipping_total'] ?? 0),
            'discount' => (float) ($order['discount_total'] ?? 0),
            'currency' => $order['currency'] ?? 'MAD',
            'payment_method' => $order['payment_method'] ?? '',
            'items' => $items,
            'created_at' => $order['date_created'] ?? null,
            'updated_at' => $order['date_modified'] ?? null,
            'metadata' => [
                'wc_order_id' => $order['id'] ?? null,
                'wc_status' => $order['status'] ?? null,
            ],
        ];
    }

    /**
     * Map WooCommerce order status to our status
     */
    private function mapOrderStatus(string $wcStatus): string
    {
        $mapping = [
            'pending' => 'pending',
            'processing' => 'processing',
            'on-hold' => 'pending',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
        ];
        
        return $mapping[strtolower($wcStatus)] ?? 'pending';
    }

    /**
     * Push product changes to WooCommerce via PUT /products/{id}.
     *
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function createProduct(Product $product): array
    {
        try {
            $payload = [
                'name'          => $product->name,
                'description'   => $product->description ?? '',
                'regular_price' => (string) $product->price,
                'sku'           => $product->sku ?? '',
                'status'        => $product->status === 'active' ? 'publish' : 'draft',
                'type'          => $product->type === 'variable' ? 'variable' : 'simple',
            ];

            $response = $this->guard($this->client()->post('/products', $payload));
            $response->throw();

            $data = $response->json();

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? ''),
                'message'     => 'Product created on WooCommerce',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('WooCommerce createProduct failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Create a variable product with all attributes and variations in one coordinated push.
     * Step 1: POST /products with type=variable and attribute definitions.
     * Step 2: POST /products/{id}/variations for each variant.
     *
     * @param  array{type: string, name: string, sku: string, price: float, description: string, status: string, attributes: array<string, list<string>>, variants: list<array{local_id: string, name: string, sku: string, price: float, attributes: array<string,mixed>, stock: int}>}  $productData
     * @return array{success: bool, external_id: string, variant_ids: array<string,string>, message: string, error: ?string}
     */
    public function createVariableProduct(array $productData): array
    {
        try {
            $wcAttributes = [];

            foreach ($productData['attributes'] as $name => $values) {
                $wcAttributes[] = [
                    'name'      => $name,
                    'visible'   => true,
                    'variation' => true,
                    'options'   => array_values($values),
                ];
            }

            $payload = [
                'name'        => $productData['name'],
                'description' => $productData['description'],
                'type'        => 'variable',
                'status'      => $productData['status'] === 'active' ? 'publish' : 'draft',
                'attributes'  => $wcAttributes,
            ];

            $response = $this->guard($this->client()->post('/products', $payload));
            $response->throw();

            $productId = (string) ($response->json('id') ?? '');

            if ($productId === '') {
                return ['success' => false, 'external_id' => '', 'variant_ids' => [], 'message' => 'WooCommerce returned no product ID', 'error' => 'no_product_id'];
            }

            $variantIds     = [];
            $failedVariants = [];

            foreach ($productData['variants'] as $variant) {
                $wcVariantAttrs = [];

                foreach ($variant['attributes'] as $attrName => $attrValue) {
                    $wcVariantAttrs[] = [
                        'name'   => $attrName,
                        'option' => is_array($attrValue) ? implode(', ', $attrValue) : (string) $attrValue,
                    ];
                }

                try {
                    $varResponse = $this->guard(
                        $this->client()->post("/products/{$productId}/variations", [
                            'sku'            => $variant['sku'],
                            'regular_price'  => (string) $variant['price'],
                            'manage_stock'   => true,
                            'stock_quantity' => $variant['stock'],
                            'attributes'     => $wcVariantAttrs,
                        ])
                    );

                    $varResponse->throw();
                    $variantIds[$variant['local_id']] = (string) ($varResponse->json('id') ?? '');
                } catch (\Throwable $e) {
                    $failedVariants[] = $variant['name'];
                    Log::warning('WooCommerce: failed to create variation', ['variant' => $variant['name'], 'error' => $e->getMessage()]);
                }
            }

            $variantCount = count($variantIds);
            $message      = empty($failedVariants)
                ? "Product created on WooCommerce with {$variantCount} variant(s)"
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
            Log::warning('WooCommerce createVariableProduct failed', ['error' => $e->getMessage()]);
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
            $isVariable = $product->type === 'variable';

            $payload = [
                'name'          => $product->name,
                'description'   => $product->description ?? '',
                'regular_price' => $isVariable ? '' : (string) $product->price,
                'status'        => $product->status === 'active' ? 'publish' : 'draft',
                'type'          => $isVariable ? 'variable' : 'simple',
                // WooCommerce rejects sku on variable products (variants own the sku)
                'sku'           => $isVariable ? '' : ($product->sku ?? ''),
            ];

            $response = $this->guard(
                $this->client()->put("/products/{$product->external_id}", $payload)
            );

            $response->throw();

            $data = $response->json();

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? $product->external_id),
                'message'     => 'Product pushed to WooCommerce successfully',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('WooCommerce pushProduct failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Create a new variant on WooCommerce via POST /products/{parent_id}/variations.
     * Before creating the variation, syncs the parent product's attribute definitions so
     * WooCommerce stores the attribute values (not just ignores them).
     *
     * @param  string|null  $productExternalId  Platform-specific product ID (overrides $variant->product->external_id)
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
            $attrs        = $variant->getAttribute('attributes') ?? [];
            $wcAttributes = [];

            foreach ($attrs as $name => $value) {
                $wcAttributes[] = [
                    'name'   => $name,
                    'option' => is_array($value) ? implode(', ', $value) : (string) $value,
                ];
            }

            // Ensure the parent product has these attributes defined with variation:true
            // so WooCommerce actually stores them on the variation instead of silently ignoring them.
            if (!empty($wcAttributes)) {
                $this->syncParentProductAttributes($parentId, $wcAttributes);
            }

            $payload = [
                'sku'            => $variant->sku ?? '',
                'regular_price'  => (string) $variant->price,
                'manage_stock'   => true,
                'stock_quantity' => 0,
                'attributes'     => $wcAttributes,
            ];

            $response = $this->guard(
                $this->client()->post("/products/{$parentId}/variations", $payload)
            );

            $response->throw();

            $data = $response->json();

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? ''),
                'message'     => 'Variant created on WooCommerce',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('WooCommerce createVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Push variant changes to WooCommerce via PUT /products/{parent_id}/variations/{id}.
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
            $attrs        = $variant->getAttribute('attributes') ?? [];
            $wcAttributes = [];

            foreach ($attrs as $name => $value) {
                $wcAttributes[] = [
                    'name'   => $name,
                    'option' => is_array($value) ? implode(', ', $value) : (string) $value,
                ];
            }

            if (!empty($wcAttributes)) {
                $this->syncParentProductAttributes($parentId, $wcAttributes);
            }

            $payload = [
                'sku'           => $variant->sku ?? '',
                'regular_price' => (string) $variant->price,
                'attributes'    => $wcAttributes,
            ];

            $response = $this->guard(
                $this->client()->put("/products/{$parentId}/variations/{$vid}", $payload)
            );

            $response->throw();

            $data = $response->json();

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? $vid),
                'message'     => 'Variant pushed to WooCommerce successfully',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('WooCommerce pushProductVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Push stock quantity to WooCommerce via PUT /products/{id}.
     * Only applies to simple products; variable product stock is per-variation.
     *
     * @return array{success: bool, message: string, error: ?string}
     */
    public function pushStock(Product $product, int $quantity): array
    {
        if (empty($product->external_id)) {
            return ['success' => false, 'message' => 'Product has no external_id', 'error' => 'missing_external_id'];
        }

        try {
            $payload = [
                'manage_stock'   => true,
                'stock_quantity' => $quantity,
                'stock_status'   => $quantity > 0 ? 'instock' : 'outofstock',
            ];

            $response = $this->guard(
                $this->client()->put("/products/{$product->external_id}", $payload)
            );

            $response->throw();

            return [
                'success' => true,
                'message' => "Stock set to {$quantity} on WooCommerce",
                'error'   => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('WooCommerce pushStock failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Delete a variant from WooCommerce via DELETE /products/{parent_id}/variations/{id}.
     * WooCommerce requires force=true to permanently delete (vs. trash).
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
                $this->client()->delete("/products/{$parentId}/variations/{$vid}", ['force' => true])
            );

            $response->throw();

            return ['success' => true, 'message' => 'Variant deleted from WooCommerce', 'error' => null];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('WooCommerce deleteVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Ensure the parent product has all variant attributes defined with variation:true.
     * WooCommerce ignores variation attribute values unless the attribute exists on the parent
     * product with variation:true and the value is in the options list.
     */
    private function syncParentProductAttributes(string $productId, array $wcVariantAttributes): void
    {
        try {
            $response = $this->guard($this->client()->get("/products/{$productId}"));

            if (!$response->successful()) {
                return;
            }

            $existingAttrs = $response->json('attributes') ?? [];
            $updated       = false;

            foreach ($wcVariantAttributes as $newAttr) {
                $name  = (string) ($newAttr['name'] ?? '');
                $value = (string) ($newAttr['option'] ?? '');

                if ($name === '') {
                    continue;
                }

                $found = false;

                foreach ($existingAttrs as &$attr) {
                    if (strtolower((string) ($attr['name'] ?? '')) === strtolower($name)) {
                        $found = true;

                        if (empty($attr['variation'])) {
                            $attr['variation'] = true;
                            $updated           = true;
                        }

                        $options = $attr['options'] ?? [];

                        if ($value !== '' && !in_array($value, $options, true)) {
                            $options[]       = $value;
                            $attr['options'] = $options;
                            $updated         = true;
                        }

                        break;
                    }
                }

                unset($attr);

                if (!$found) {
                    $existingAttrs[] = [
                        'name'      => $name,
                        'visible'   => true,
                        'variation' => true,
                        'options'   => $value !== '' ? [$value] : [],
                    ];
                    $updated = true;
                }
            }

            if ($updated) {
                $this->guard(
                    $this->client()->put("/products/{$productId}", ['attributes' => $existingAttrs])
                );
            }
        } catch (\Throwable $e) {
            Log::warning('WooCommerce: failed to sync parent product attributes', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}