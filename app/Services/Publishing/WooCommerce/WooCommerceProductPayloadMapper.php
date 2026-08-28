<?php

declare(strict_types=1);

namespace App\Services\Publishing\WooCommerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Publishing\ProductOptionSnapshot;
use App\Services\Publishing\ProductPublishReadinessService;

/**
 * Converts a canonical SaaS Product into WooCommerce REST payloads — a
 * simple product payload, or a variable-product parent payload plus a
 * separate list of variation payloads. Pure mapping only — no HTTP.
 */
class WooCommerceProductPayloadMapper
{
    public function __construct(private readonly ProductPublishReadinessService $readiness) {}

    /**
     * @return array{
     *     ready: bool,
     *     errors: list<string>,
     *     payload: ?array,
     *     variations: list<array{variant: ProductVariant, payload: array}>,
     * }
     */
    public function map(Product $product): array
    {
        $check = $this->readiness->woocommerce($product);

        if ($check['status'] === ProductPublishReadinessService::STATUS_BLOCKED) {
            return ['ready' => false, 'errors' => $check['errors'], 'payload' => null, 'variations' => []];
        }

        $snapshot = ProductOptionSnapshot::build($product);

        if (! $product->isVariable() || $snapshot['options'] === []) {
            return [
                'ready' => true,
                'errors' => [],
                'payload' => $this->simplePayload($product),
                'variations' => [],
            ];
        }

        return [
            'ready' => true,
            'errors' => [],
            'payload' => $this->variablePayload($product, $snapshot),
            'variations' => array_map(
                fn (array $entry) => ['variant' => $entry['variant'], 'payload' => $this->variationPayload($entry['variant'], $entry['combo'])],
                $snapshot['variants'],
            ),
        ];
    }

    private function simplePayload(Product $product): array
    {
        return [
            'name' => $product->name,
            'description' => $product->description ?? '',
            'sku' => $product->sku ?? '',
            'regular_price' => (string) $product->price,
            'status' => $product->status === 'active' ? 'publish' : 'draft',
            'type' => 'simple',
        ];
    }

    /**
     * @param array{options: list<array{name: string, values: list<string>}>} $snapshot
     */
    private function variablePayload(Product $product, array $snapshot): array
    {
        $attributes = array_map(fn (array $option) => [
            'name' => $option['name'],
            'visible' => true,
            'variation' => true,
            'options' => $option['values'],
        ], $snapshot['options']);

        return [
            'name' => $product->name,
            'description' => $product->description ?? '',
            'status' => $product->status === 'active' ? 'publish' : 'draft',
            'type' => 'variable',
            // WooCommerce rejects a top-level sku/regular_price on a variable
            // product — those live on each variation instead.
            'attributes' => $attributes,
        ];
    }

    /** @param array<string, string> $combo */
    public function variationPayload(ProductVariant $variant, array $combo): array
    {
        return [
            'sku' => $variant->sku ?? '',
            'regular_price' => (string) $variant->price,
            'attributes' => array_map(
                fn (string $name, string $value) => ['name' => $name, 'option' => $value],
                array_keys($combo),
                array_values($combo),
            ),
        ];
    }
}
