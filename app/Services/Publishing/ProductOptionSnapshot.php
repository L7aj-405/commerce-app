<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Reads a Product's canonical ProductAttribute/ProductAttributeValue/variant
 * pivot data into a simple, deterministic shape every publish mapper and the
 * readiness service can share. Never reads the legacy `attributes` JSON
 * column or a variant's display name/title — option position (set by
 * ProductVariantWizardService) is the only thing that decides order.
 *
 * `product.type` is authoritative: a simple product always snapshots as
 * empty, even if stale ProductAttribute/ProductAttributeValue/ProductVariant
 * rows are still sitting active in the DB from a prior variable state (e.g.
 * a product tested as variable, then reverted to simple in Shopify and
 * synced back — nothing walks the old canonical rows and archives them on
 * every write path). Never let leftover option/variant data resurface for a
 * product the SaaS itself considers simple.
 */
class ProductOptionSnapshot
{
    /**
     * @return array{
     *     options: list<array{name: string, values: list<string>}>,
     *     variants: list<array{variant: ProductVariant, combo: array<string, string>}>,
     * }
     */
    public static function build(Product $product): array
    {
        if (! $product->isVariable()) {
            return ['options' => [], 'variants' => []];
        }

        // Always reloaded fresh (never loadMissing) — a stale eager-loaded
        // `attributes.values` from before an archive/reactivate wouldn't
        // reflect the just-changed `is_active` flags, and this snapshot is
        // the single source of truth every mapper/readiness check relies on.
        $product->load([
            'attributes' => fn ($q) => $q->orderBy('position'),
            'attributes.values' => fn ($q) => $q->active()->orderBy('position'),
            'variants.attributeValues' => fn ($q) => $q->active(),
            'variants.attributeValues.attribute',
        ]);

        $options = $product->attributes
            // An attribute with zero active values is a fully-retired
            // option that just hasn't been garbage-collected yet — it must
            // never resurface as a phantom option with an empty value list.
            ->filter(fn ($attribute) => $attribute->values->isNotEmpty())
            ->values()
            ->map(fn ($attribute) => [
                'name' => (string) $attribute->name,
                'values' => $attribute->values->pluck('value')->map(fn ($v) => (string) $v)->all(),
            ])
            ->all();

        $variants = $product->variants
            ->map(function (ProductVariant $variant): array {
                $combo = $variant->attributeValues
                    ->mapWithKeys(fn ($value) => [(string) $value->attribute->name => (string) $value->value])
                    ->all();

                return ['variant' => $variant, 'combo' => $combo];
            })
            ->all();

        return ['options' => $options, 'variants' => $variants];
    }

    /** Sorted "Name:Value|Name:Value" key — the canonical variant identity. */
    public static function comboKey(array $combo): string
    {
        $pairs = [];

        foreach ($combo as $name => $value) {
            $pairs[] = "{$name}:{$value}";
        }

        sort($pairs);

        return implode('|', $pairs);
    }
}
