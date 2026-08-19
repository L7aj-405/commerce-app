<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Exceptions\ConnectorException;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shopify Admin REST API connector (version 2024-01).
 * Shopify is moving to GraphQL; to migrate, replace REST calls with
 * POST /admin/api/2024-01/graphql.json and update parseProduct/parseOrder accordingly.
 */
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
            throw new ConnectorException(
                message: 'Shopify rate limit exceeded',
                platform: $this->getPlatform(),
                context: ['retry_after' => $response->header('Retry-After')],
            );
        }

        return $response;
    }

    public function authenticate(): bool
    {
        try {
            $response = $this->guard($this->client()->get('/shop.json'));

            return $response->successful() && array_key_exists('shop', $response->json() ?? []);
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Shopify REST uses cursor-based pagination via the Link header.
     * This implementation fetches the first page up to $perPage records.
     * For full cursor support, pass the page_info token via the connection settings.
     */
    public function getProducts(int $page = 1, int $perPage = 50): array
    {
        try {
            $response = $this->guard(
                $this->client()->get('/products.json', [
                    'limit'  => $perPage,
                    'status' => 'active',
                ])
            );

            $response->throw();

            return array_map(
                fn (array $p) => $this->normalizeProduct($this->parseProduct($p)),
                $response->json('products') ?? [],
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    public function getOrders(int $page = 1, int $perPage = 50, ?CarbonInterface $since = null): array
    {
        try {
            $params = ['limit' => $perPage, 'status' => 'any'];

            if ($since !== null) {
                $params['created_at_min'] = $since->toIso8601String();
            }

            $response = $this->guard($this->client()->get('/orders.json', $params));
            $response->throw();

            return array_map(
                fn (array $o) => $this->normalizeOrder($this->parseOrder($o)),
                $response->json('orders') ?? [],
            );
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Every Shopify product has at least one variant. The first variant drives
     * the base price and SKU; all variants are stored individually.
     */
    protected function parseProduct(array $product): array
    {
        $firstVariant = $product['variants'][0] ?? [];

        $variants = array_map(function (array $v) {
            return [
                'external_id'   => (string) $v['id'],
                'name'          => $this->buildVariantTitle($v),
                'sku'           => $v['sku'] ?? null,
                'price'         => (float) ($v['price'] ?? 0),
                'compare_price' => (float) ($v['compare_at_price'] ?? 0) ?: null,
                'attributes'    => array_filter([
                    'option1' => $v['option1'] ?? null,
                    'option2' => $v['option2'] ?? null,
                    'option3' => $v['option3'] ?? null,
                ]),
                'images' => [],
            ];
        }, $product['variants'] ?? []);

        return [
            'platform_id'   => (string) $product['id'],
            'name'          => $product['title'] ?? '',
            'description'   => strip_tags($product['body_html'] ?? ''),
            'sku'           => $firstVariant['sku'] ?? null,
            'type'          => count($product['variants'] ?? []) > 1 ? 'variable' : 'simple',
            'price'         => (float) ($firstVariant['price'] ?? 0),
            'compare_price' => (float) ($firstVariant['compare_at_price'] ?? 0) ?: null,
            'stock'         => isset($firstVariant['inventory_quantity'])
                ? (int) $firstVariant['inventory_quantity']
                : null,
            'status'        => $product['status'] ?? 'unknown',
            'featured_image' => $product['images'][0]['src'] ?? null,
            'images'        => array_column($product['images'] ?? [], 'src'),
            'variants'      => $variants,
            'platform_data' => $product,
        ];
    }

    protected function parseOrder(array $order): array
    {
        $customer     = $order['customer'] ?? [];
        $shipping     = $order['shipping_address'] ?? [];
        $customerName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));

        $items = array_map(fn (array $item) => [
            'external_id' => (string) $item['id'],
            'product_id'  => (string) $item['product_id'],
            'variant_id'  => (string) ($item['variant_id'] ?? ''),
            'name'        => $item['name'] ?? '',
            'sku'         => $item['sku'] ?? null,
            'quantity'    => (int) ($item['quantity'] ?? 1),
            'price'       => (float) ($item['price'] ?? 0),
            'subtotal'    => (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1),
        ], $order['line_items'] ?? []);

        return [
            'platform_id'    => (string) $order['id'],
            'number'         => '#' . ($order['order_number'] ?? $order['id']),
            'status'         => $order['financial_status'] ?? 'unknown',
            'total'          => (float) ($order['current_total_price'] ?? 0),
            'currency'       => $order['currency'] ?? 'MAD',
            'customer_name'  => $customerName !== '' ? $customerName : 'Unknown',
            'customer_email' => $customer['email'] ?? null,
            'customer_phone' => $shipping['phone'] ?? $customer['phone'] ?? null,
            'items'          => $items,
            'created_at'     => $order['created_at'] ?? now()->toIso8601String(),
            'platform_data'  => $order,
        ];
    }

    /**
     * Fetch all variants for a single product, with properly named attributes.
     * Fetches the full product so the `options` array is available to map
     * option1/option2/option3 positions to human-readable names (Color, Size, …).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProductVariants(string $productId, int $page = 1, int $perPage = 100): array
    {
        try {
            $response = $this->guard(
                $this->client()->get("/products/{$productId}.json")
            );

            $response->throw();

            $product = $response->json('product') ?? [];

            return $this->parseVariantsFromProduct($product);
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->handleRequestException($e);
        }
    }

    /**
     * Extract and normalize all variants from a full Shopify product payload.
     * Maps option1/2/3 positions to named attributes using the product's options array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseVariantsFromProduct(array $product): array
    {
        // Build position → name map from product options (e.g. {1: "Color", 2: "Size"})
        $optionNames = [];
        foreach ($product['options'] ?? [] as $option) {
            if (isset($option['position'], $option['name'])) {
                $optionNames[(int) $option['position']] = (string) $option['name'];
            }
        }

        return array_map(function (array $v) use ($optionNames): array {
            $attributes = [];

            foreach ([1, 2, 3] as $pos) {
                $value = $v["option{$pos}"] ?? null;
                $label = $optionNames[$pos] ?? null;

                if ($value !== null && $value !== '' && $label !== null) {
                    $attributes[$label] = $value;
                }
            }

            return [
                'external_id'   => (string) $v['id'],
                'name'          => $this->buildVariantTitle($v),
                'sku'           => (string) ($v['sku'] ?? ''),
                'price'         => (float) ($v['price'] ?? 0),
                'cost'          => 0,
                'compare_price' => (float) ($v['compare_at_price'] ?? 0),
                'stock'         => isset($v['inventory_quantity']) ? (int) $v['inventory_quantity'] : null,
                'attributes'    => $attributes,
            ];
        }, $product['variants'] ?? []);
    }

    private function buildVariantTitle(array $variant): string
    {
        return collect([$variant['option1'] ?? null, $variant['option2'] ?? null, $variant['option3'] ?? null])
            ->filter()
            ->join(' / ');
    }

    /**
     * Push product changes to Shopify via PUT /products/{id}.json.
     *
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function createProduct(Product $product): array
    {
        try {
            $payload = [
                'product' => [
                    'title'     => $product->name,
                    'body_html' => $product->description ?? '',
                    'status'    => $product->status === 'active' ? 'active' : 'draft',
                    'variants'  => [
                        [
                            'sku'   => $product->sku ?? '',
                            'price' => number_format((float) $product->price, 2, '.', ''),
                        ],
                    ],
                ],
            ];

            $response = $this->guard($this->client()->post('/products.json', $payload));
            $response->throw();

            $data = $response->json('product') ?? [];

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? ''),
                'message'     => 'Product created on Shopify',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify createProduct failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Create a variable product with options and all variants in one Shopify product POST.
     * Shopify natively supports embedding variants + options in the product creation payload.
     * Variants are matched back to local IDs by SKU.
     *
     * @param  array{type: string, name: string, sku: string, price: float, description: string, status: string, attributes: array<string, list<string>>, variants: list<array{local_id: string, name: string, sku: string, price: float, attributes: array<string,mixed>, stock: int}>}  $productData
     * @return array{success: bool, external_id: string, variant_ids: array<string,string>, message: string, error: ?string}
     */
    public function createVariableProduct(array $productData): array
    {
        try {
            $attrNames = array_keys($productData['attributes']);
            $options   = array_map(fn (string $n) => ['name' => $n], array_slice($attrNames, 0, 3));

            // Build name → position map (1-based) for option slot assignment
            $positions = [];

            foreach (array_slice($attrNames, 0, 3) as $idx => $name) {
                $positions[strtolower($name)] = $idx + 1;
            }

            $shopifyVariants = array_map(function (array $variant) use ($positions): array {
                $optionValues = [];

                foreach ($variant['attributes'] as $attrName => $attrValue) {
                    $pos = $positions[strtolower($attrName)] ?? null;

                    if ($pos !== null && $pos <= 3) {
                        $optionValues["option{$pos}"] = is_array($attrValue) ? implode(', ', $attrValue) : (string) $attrValue;
                    }
                }

                return array_merge([
                    'sku'                  => $variant['sku'],
                    'price'                => number_format($variant['price'], 2, '.', ''),
                    'inventory_management' => 'shopify',
                    'inventory_quantity'   => $variant['stock'],
                ], $optionValues);
            }, $productData['variants']);

            $payload = [
                'product' => [
                    'title'     => $productData['name'],
                    'body_html' => $productData['description'],
                    'status'    => $productData['status'] === 'active' ? 'active' : 'draft',
                    'options'   => $options,
                    'variants'  => $shopifyVariants,
                ],
            ];

            $response = $this->guard($this->client()->post('/products.json', $payload));
            $response->throw();

            $shopifyProduct = $response->json('product') ?? [];
            $productId      = (string) ($shopifyProduct['id'] ?? '');

            if ($productId === '') {
                return ['success' => false, 'external_id' => '', 'variant_ids' => [], 'message' => 'Shopify returned no product ID', 'error' => 'no_product_id'];
            }

            // Match Shopify variants back to local variant IDs by SKU
            $skuToShopifyId = [];

            foreach ($shopifyProduct['variants'] ?? [] as $sv) {
                if (!empty($sv['sku'])) {
                    $skuToShopifyId[(string) $sv['sku']] = (string) $sv['id'];
                }
            }

            $variantIds = [];

            foreach ($productData['variants'] as $variant) {
                $shopifyId = $skuToShopifyId[$variant['sku']] ?? null;

                if ($shopifyId !== null) {
                    $variantIds[$variant['local_id']] = $shopifyId;
                }
            }

            $variantCount = count($variantIds);

            return [
                'success'     => true,
                'external_id' => $productId,
                'variant_ids' => $variantIds,
                'message'     => "Product created on Shopify with {$variantCount} variant(s)",
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify createVariableProduct failed', ['error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * @return array{success: bool, external_id: string, message: string, error: ?string}
     */
    public function pushProduct(Product $product, ?string $productExternalId = null): array
    {
        $externalId = $productExternalId ?? $product->external_id;

        if (empty($externalId)) {
            return ['success' => false, 'external_id' => '', 'message' => 'Product has no external_id', 'error' => 'missing_external_id'];
        }

        try {
            $payload = [
                'product' => [
                    'title'     => $product->name,
                    'body_html' => $product->description ?? '',
                    'status'    => $product->status === 'active' ? 'active' : 'draft',
                    'variants'  => [
                        [
                            'sku'             => $product->sku ?? '',
                            'price'           => number_format((float) $product->price, 2, '.', ''),
                            'compare_at_price' => $product->compare_price > 0
                                ? number_format((float) $product->compare_price, 2, '.', '')
                                : null,
                        ],
                    ],
                ],
            ];

            $response = $this->guard(
                $this->client()->put("/products/{$externalId}.json", $payload)
            );

            $response->throw();

            $data = $response->json('product') ?? [];

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? $externalId),
                'message'     => 'Product pushed to Shopify successfully',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify pushProduct failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Create a new variant on Shopify via POST /products/{product_id}/variants.json.
     * Maps variant attributes to Shopify's positional option slots (option1/option2/option3).
     * Ensures the parent product has the required options defined first.
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

            // Ensure Shopify product has options matching our attribute names, get name→position map
            $optionPositions = $this->getOrSyncProductOptions($parentId, array_keys($attrs));

            // Map attribute values to Shopify's positional option slots
            $optionValues = [];

            foreach ($attrs as $name => $value) {
                $pos = $optionPositions[strtolower($name)] ?? null;

                if ($pos !== null && $pos <= 3) {
                    $optionValues["option{$pos}"] = is_array($value) ? implode(', ', $value) : (string) $value;
                }
            }

            $payload = [
                'variant' => array_merge([
                    'sku'                  => $variant->sku ?? '',
                    'price'                => number_format((float) $variant->price, 2, '.', ''),
                    'compare_at_price'     => $variant->compare_price > 0
                        ? number_format((float) $variant->compare_price, 2, '.', '')
                        : null,
                    'inventory_management' => 'shopify',
                    'inventory_quantity'   => 0,
                ], $optionValues),
            ];

            $response = $this->guard(
                $this->client()->post("/products/{$parentId}/variants.json", $payload)
            );

            $response->throw();

            $data = $response->json('variant') ?? [];

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? ''),
                'message'     => 'Variant created on Shopify',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify createVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Push variant changes to Shopify via PUT /variants/{id}.json.
     *
     * @param  string|null  $productExternalId  Platform-specific product ID (used for option lookup)
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

        try {
            $attrs        = $variant->getAttribute('attributes') ?? [];
            $optionValues = [];

            if (!empty($attrs) && !empty($parentId)) {
                $optionPositions = $this->getOrSyncProductOptions($parentId, array_keys($attrs));

                foreach ($attrs as $name => $value) {
                    $pos = $optionPositions[strtolower($name)] ?? null;

                    if ($pos !== null && $pos <= 3) {
                        $optionValues["option{$pos}"] = is_array($value) ? implode(', ', $value) : (string) $value;
                    }
                }
            }

            $payload = [
                'variant' => array_merge([
                    'sku'              => $variant->sku ?? '',
                    'price'            => number_format((float) $variant->price, 2, '.', ''),
                    'compare_at_price' => $variant->compare_price > 0
                        ? number_format((float) $variant->compare_price, 2, '.', '')
                        : null,
                ], $optionValues),
            ];

            $response = $this->guard(
                $this->client()->put("/variants/{$vid}.json", $payload)
            );

            $response->throw();

            $data = $response->json('variant') ?? [];

            return [
                'success'     => true,
                'external_id' => (string) ($data['id'] ?? $vid),
                'message'     => 'Variant pushed to Shopify successfully',
                'error'       => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify pushProductVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Push stock quantity to Shopify via inventory_levels/set.json.
     * Requires a location — fetches the first available location automatically.
     *
     * @return array{success: bool, message: string, error: ?string}
     */
    public function pushStock(Product $product, int $quantity, ?string $productExternalId = null): array
    {
        $externalId = $productExternalId ?? $product->external_id;

        if (empty($externalId)) {
            return ['success' => false, 'message' => 'Product has no external_id', 'error' => 'missing_external_id'];
        }

        try {
            // Get the product to find the first variant's inventory_item_id
            $productResponse = $this->guard(
                $this->client()->get("/products/{$externalId}.json")
            );

            $productResponse->throw();

            $variants        = $productResponse->json('product.variants') ?? [];
            $firstVariant    = $variants[0] ?? null;
            $inventoryItemId = $firstVariant['inventory_item_id'] ?? null;

            if ($inventoryItemId === null) {
                return ['success' => false, 'message' => 'No inventory_item_id found on Shopify product', 'error' => 'no_inventory_item'];
            }

            // Get the first available location
            $locationResponse = $this->guard($this->client()->get('/locations.json'));
            $locationResponse->throw();

            $locations  = $locationResponse->json('locations') ?? [];
            $locationId = $locations[0]['id'] ?? null;

            if ($locationId === null) {
                return ['success' => false, 'message' => 'No Shopify location found', 'error' => 'no_location'];
            }

            // Set inventory level
            $setResponse = $this->guard(
                $this->client()->post('/inventory_levels/set.json', [
                    'location_id'       => $locationId,
                    'inventory_item_id' => $inventoryItemId,
                    'available'         => $quantity,
                ])
            );

            $setResponse->throw();

            return [
                'success' => true,
                'message' => "Stock set to {$quantity} on Shopify",
                'error'   => null,
            ];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify pushStock failed', ['product' => $product->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Delete a variant from Shopify via DELETE /products/{product_id}/variants/{id}.json.
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
                $this->client()->delete("/products/{$parentId}/variants/{$vid}.json")
            );

            $response->throw();

            return ['success' => true, 'message' => 'Variant deleted from Shopify', 'error' => null];
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::warning('Shopify deleteVariant failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
            throw ConnectorException::connectionFailed($this->getPlatform(), $e->getMessage());
        }
    }

    /**
     * Ensure the Shopify product has options matching the given attribute names.
     * Returns a map of lowercase attribute name → Shopify option position (1, 2, 3).
     *
     * If the product only has the default "Title" option, replaces it with the attribute names.
     * Otherwise merges missing attribute names into the existing options list.
     *
     * @param  string[]  $attrNames
     * @return array<string, int>  e.g. ['size' => 1, 'color' => 2]
     */
    private function getOrSyncProductOptions(string $productId, array $attrNames): array
    {
        try {
            $response = $this->guard($this->client()->get("/products/{$productId}.json"));

            if (!$response->successful()) {
                return [];
            }

            $existingOptions = $response->json('product.options') ?? [];

            // Build lowercase name → position map from existing options
            $positions = [];

            foreach ($existingOptions as $opt) {
                if (isset($opt['name'], $opt['position'])) {
                    $positions[strtolower((string) $opt['name'])] = (int) $opt['position'];
                }
            }

            // Determine which attribute names are missing from the product's options
            $missing = [];

            foreach ($attrNames as $name) {
                if (!isset($positions[strtolower($name)])) {
                    $missing[] = $name;
                }
            }

            if (empty($missing)) {
                return $positions;
            }

            // Check if the product only has the default Shopify "Title" option
            $isDefaultOnly = count($existingOptions) === 1
                && strtolower((string) ($existingOptions[0]['name'] ?? '')) === 'title';

            if ($isDefaultOnly) {
                // Replace the default Title option with our attribute names (up to 3)
                $newOptions = array_map(fn (string $n) => ['name' => $n], array_slice($attrNames, 0, 3));
            } else {
                // Append missing names while respecting Shopify's 3-option limit
                $newOptions = array_map(fn ($opt) => ['name' => $opt['name']], $existingOptions);

                foreach ($missing as $name) {
                    if (count($newOptions) >= 3) {
                        break;
                    }

                    $newOptions[] = ['name' => $name];
                }
            }

            $this->guard(
                $this->client()->put("/products/{$productId}.json", [
                    'product' => ['options' => $newOptions],
                ])
            );

            // Rebuild position map from the updated options list
            $positions = [];

            foreach ($newOptions as $idx => $opt) {
                $positions[strtolower((string) ($opt['name'] ?? ''))] = $idx + 1;
            }

            return $positions;
        } catch (\Throwable $e) {
            Log::warning('Shopify: failed to sync product options', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);

            return [];
        }
    }
}
