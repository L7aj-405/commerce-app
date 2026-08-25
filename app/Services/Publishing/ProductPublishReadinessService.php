<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Product;

/**
 * Read-only readiness checks — never mutates anything, never calls a
 * platform API. Shared by the Product Edit readiness panel (informational,
 * never blocks saving) and by the Shopify/WooCommerce publish mappers
 * (blocking — a "blocked" result must never reach a platform payload).
 */
class ProductPublishReadinessService
{
    public const STATUS_READY = 'ready';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCKED = 'blocked';

    /** @return array{shopify: array{status: string, warnings: list<string>, errors: list<string>}, woocommerce: array{status: string, warnings: list<string>, errors: list<string>}} */
    public function check(Product $product): array
    {
        return [
            'shopify' => $this->shopify($product),
            'woocommerce' => $this->woocommerce($product),
        ];
    }

    /** @return array{status: string, warnings: list<string>, errors: list<string>} */
    public function shopify(Product $product): array
    {
        $errors = [];
        $warnings = [];

        if (trim((string) $product->name) === '') {
            $errors[] = 'Product has no name.';
        }

        if (! $product->isVariable()) {
            // product.type is authoritative: a simple product is never
            // blocked by option/variant checks, no matter what stale
            // canonical data (from a prior variable test, or an external
            // sync that reverted the product to simple) might still be
            // sitting active in the DB — ProductOptionSnapshot enforces the
            // same rule, this is belt-and-suspenders for clarity here.
            return $this->result($errors, $warnings);
        }

        $snapshot = ProductOptionSnapshot::build($product);
        $options = $snapshot['options'];
        $variants = $snapshot['variants'];

        if (count($options) > 3) {
            $errors[] = 'Cannot publish to Shopify because Shopify supports a maximum of 3 variant options.';
        }

        foreach ($options as $option) {
            if (trim($option['name']) === '') {
                $errors[] = 'Cannot publish to Shopify because one or more options have a blank name.';
            }

            foreach ($option['values'] as $value) {
                if (trim($value) === '') {
                    $errors[] = 'Cannot publish to Shopify because one or more option values are blank.';
                }
            }
        }

        if ($options !== [] && $variants === []) {
            // Shopify rejects an options-only product update/create with a
            // 422 ("Product options must have corresponding variants") — so
            // this must block the publish, not just warn about it.
            $errors[] = 'Cannot publish to Shopify because the product has options defined but no variants yet — generate variants before publishing.';
        }

        if ($options !== [] && $variants !== []) {
            $optionNames = array_column($options, 'name');
            $seenCombos = [];

            foreach ($variants as $entry) {
                $missing = array_diff($optionNames, array_keys($entry['combo']));

                if ($missing !== []) {
                    $errors[] = 'Cannot publish to Shopify because one or more variants are missing option values.';
                }

                $comboKey = ProductOptionSnapshot::comboKey($entry['combo']);

                if (isset($seenCombos[$comboKey])) {
                    $errors[] = 'Cannot publish to Shopify because duplicate variant option combinations exist.';
                }

                $seenCombos[$comboKey] = true;
            }
        }

        return $this->result($errors, $warnings);
    }

    /** @return array{status: string, warnings: list<string>, errors: list<string>} */
    public function woocommerce(Product $product): array
    {
        $errors = [];
        $warnings = [];

        if (trim((string) $product->name) === '') {
            $errors[] = 'Product has no name.';
        }

        $snapshot = ProductOptionSnapshot::build($product);
        $options = $snapshot['options'];
        $variants = $snapshot['variants'];
        $isVariable = $product->isVariable();

        if ($isVariable) {
            if ($options === []) {
                $errors[] = 'WooCommerce variable product requires at least one attribute.';
            }

            if ($variants === []) {
                $errors[] = 'WooCommerce variable product requires at least one variant.';
            }

            if ($options !== [] && $variants !== []) {
                $optionNames = array_column($options, 'name');
                $seenCombos = [];

                foreach ($variants as $entry) {
                    $missing = array_diff($optionNames, array_keys($entry['combo']));

                    if ($missing !== []) {
                        $errors[] = 'Cannot publish to WooCommerce because one or more variations are missing attribute values.';
                    }

                    $comboKey = ProductOptionSnapshot::comboKey($entry['combo']);

                    if (isset($seenCombos[$comboKey])) {
                        $errors[] = 'Cannot publish to WooCommerce because duplicate variation attribute combinations exist.';
                    }

                    $seenCombos[$comboKey] = true;
                }
            }
        }

        return $this->result($errors, $warnings);
    }

    /** @param list<string> $errors @param list<string> $warnings @return array{status: string, warnings: list<string>, errors: list<string>} */
    private function result(array $errors, array $warnings): array
    {
        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));

        $status = match (true) {
            $errors !== [] => self::STATUS_BLOCKED,
            $warnings !== [] => self::STATUS_WARNING,
            default => self::STATUS_READY,
        };

        return ['status' => $status, 'warnings' => $warnings, 'errors' => $errors];
    }
}
