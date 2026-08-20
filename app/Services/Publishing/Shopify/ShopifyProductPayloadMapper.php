<?php

declare(strict_types=1);

namespace App\Services\Publishing\Shopify;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Publishing\ProductOptionSnapshot;
use App\Services\Publishing\ProductPublishReadinessService;

/**
 * Converts a canonical SaaS Product into a Shopify Admin REST product
 * payload. Pure mapping only — no HTTP. Never trusts a variant's display
 * name/title; option1/option2/option3 are always resolved from the
 * canonical ProductAttribute/ProductAttributeValue combination via
 * ProductOptionSnapshot, in position order.
 */
class ShopifyProductPayloadMapper
{
    public function __construct(private readonly ProductPublishReadinessService $readiness) {}

    /**
     * @return array{
     *     ready: bool,
     *     errors: list<string>,
     *     payload: ?array,
     *     variant_skus: array<string, string>,
     * }
     */
    public function map(Product $product): array
    {
        $check = $this->readiness->shopify($product);

        if ($check['status'] === ProductPublishReadinessService::STATUS_BLOCKED) {
            return ['ready' => false, 'errors' => $check['errors'], 'payload' => null, 'variant_skus' => []];
        }

        $snapshot = ProductOptionSnapshot::build($product);

        if ($snapshot['options'] === []) {
            return [
                'ready' => true,
                'errors' => [],
                'payload' => $this->simplePayload($product),
                'variant_skus' => [],
            ];
        }

        return [
            'ready' => true,
            'errors' => [],
            'payload' => $this->variablePayload($product, $snapshot),
            'variant_skus' => $this->variantSkuMap($snapshot),
        ];
    }

    /** A basic Shopify product — no canonical options, one default variant carries sku/price. */
    private function simplePayload(Product $product): array
    {
        $payload = [
            'title' => $product->name,
            'body_html' => $product->description ?? '',
            'status' => $product->status === 'active' ? 'active' : 'draft',
            'variants' => [
                array_filter([
                    'sku' => $product->sku ?? '',
                    'price' => number_format((float) $product->price, 2, '.', ''),
                ], fn ($v) => $v !== null),
            ],
        ];

        return $this->withOptionalFields($payload, $product);
    }

    /**
     * @param array{options: list<array{name: string, values: list<string>}>, variants: list<array{variant: ProductVariant, combo: array<string, string>}>} $snapshot
     */
    private function variablePayload(Product $product, array $snapshot): array
    {
        $optionNames = array_column($snapshot['options'], 'name');

        $payload = [
            'title' => $product->name,
            'body_html' => $product->description ?? '',
            'status' => $product->status === 'active' ? 'active' : 'draft',
            'options' => array_map(fn (string $name) => ['name' => $name], $optionNames),
            'variants' => array_map(
                fn (array $entry) => $this->variantPayload($entry['variant'], $entry['combo'], $optionNames),
                $snapshot['variants'],
            ),
        ];

        return $this->withOptionalFields($payload, $product);
    }

    /**
     * A single Shopify variant slot: option1/option2/option3 resolved by
     * canonical option position — never by parsing a display string.
     *
     * @param  array<string, string>  $combo
     * @param  list<string>  $optionNames
     */
    public function variantPayload(ProductVariant $variant, array $combo, array $optionNames): array
    {
        $slots = [];

        foreach (array_values($optionNames) as $index => $name) {
            if ($index >= 3) {
                break;
            }

            $slots['option' . ($index + 1)] = $combo[$name] ?? null;
        }

        return array_filter(array_merge([
            'sku' => $variant->sku ?? '',
            'price' => number_format((float) $variant->price, 2, '.', ''),
        ], $slots), fn ($v) => $v !== null);
    }

    /** @param array{variants: list<array{variant: ProductVariant, combo: array<string, string>}>} $snapshot @return array<string, string> */
    private function variantSkuMap(array $snapshot): array
    {
        $map = [];

        foreach ($snapshot['variants'] as $entry) {
            if (! empty($entry['variant']->sku)) {
                $map[$entry['variant']->id] = $entry['variant']->sku;
            }
        }

        return $map;
    }

    private function withOptionalFields(array $payload, Product $product): array
    {
        if (! empty($product->category)) {
            $payload['product_type'] = $product->category;
        }

        $vendor = $product->relationLoaded('store') ? $product->store?->name : null;

        if (! empty($vendor)) {
            $payload['vendor'] = $vendor;
        }

        return $payload;
    }
}
