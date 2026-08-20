<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\VariantInventoryLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a product-edit-wizard submission (option definitions + variant
 * rows) into the canonical ProductAttribute/ProductAttributeValue/
 * product_variant_attribute_values structure — the same tables
 * ProductSyncService already populates on import, just now also driven by
 * the manual wizard. Variants are matched to existing rows by their
 * canonical option-value combination (never by title string), so editing
 * fields never loses the SKU/price/channel-listing/inventory-link of a
 * combination that still exists, and a combination that's gone but is
 * still externally linked is never silently destroyed.
 */
class ProductVariantWizardService
{
    /**
     * @param  array<int, array{name: string, values: array<int, string>}>  $options
     * @param  array<int, array{id?: ?string, sku?: ?string, price?: mixed, compare_price?: mixed, cost?: mixed, status?: ?string, options: array<string, string>}>  $variants
     * @return array{variants: Collection<int, ProductVariant>, warnings: array<int, string>}
     */
    public function sync(Product $product, array $options, array $variants): array
    {
        $options = $this->validateOptions($options);
        $optionNames = collect($options)->pluck('name')->all();

        $variants = $this->validateVariants($variants, $optionNames);

        // 1. Upsert option definitions + values, building name+value -> id.
        $valueIdByNameAndValue = [];
        $submittedAttributeIds = [];
        $submittedValueIdsByAttribute = [];

        foreach ($options as $position => $option) {
            $attribute = ProductAttribute::findOrCreateForProduct($product->id, $option['name']);
            $attribute->update(['position' => $position]);
            $submittedAttributeIds[] = $attribute->id;
            $submittedValueIdsByAttribute[$attribute->id] = [];

            foreach ($option['values'] as $valuePosition => $value) {
                $attributeValue = ProductAttributeValue::findOrCreateForAttribute($attribute->id, $value);
                $attributeValue->update(['position' => $valuePosition]);
                $valueIdByNameAndValue[$option['name'] . '::' . $value] = $attributeValue->id;
                $submittedValueIdsByAttribute[$attribute->id][] = $attributeValue->id;
            }
        }

        // 2. Resolve each submitted variant's combination to a sorted set of
        // ProductAttributeValue ids — this IS the canonical identity of a
        // variant, not its title string.
        $submittedCombos = [];
        foreach ($variants as $index => $variant) {
            $ids = [];
            foreach ($variant['options'] as $name => $value) {
                $key = $name . '::' . $value;
                if (! isset($valueIdByNameAndValue[$key])) {
                    throw ValidationException::withMessages([
                        "variants.{$index}.options" => "\"{$value}\" is not a defined value for \"{$name}\".",
                    ]);
                }
                $ids[] = $valueIdByNameAndValue[$key];
            }
            sort($ids);
            $submittedCombos[$index] = $ids;
        }

        // Reject duplicate combinations within the submission itself.
        $seen = [];
        foreach ($submittedCombos as $index => $ids) {
            $comboKey = implode(',', $ids);
            if (isset($seen[$comboKey])) {
                throw ValidationException::withMessages([
                    "variants.{$index}.options" => 'This option combination is already used by another variant in this submission.',
                ]);
            }
            $seen[$comboKey] = true;
        }

        // 3. Match against existing (non-deleted) variants by the same
        // canonical combination.
        $existingVariants = $product->variants()->with('attributeValues')->get();
        $existingByCombo = [];
        foreach ($existingVariants as $existing) {
            $ids = $existing->attributeValues->pluck('id')->sort()->values()->all();
            $existingByCombo[implode(',', $ids)] = $existing;
        }

        $claimedVariantIds = [];
        $resultVariants = collect();

        foreach ($variants as $index => $variantData) {
            $comboKey = implode(',', $submittedCombos[$index]);
            $matched = $existingByCombo[$comboKey] ?? null;

            // A client-sent id is only ever a hint, re-verified through the
            // product's own relation — never trusted to belong to this
            // product on its own (same anti-IDOR pattern as before).
            if ($matched === null && ! empty($variantData['id'])) {
                $byId = $product->variants()->whereKey($variantData['id'])->first();
                if ($byId !== null && ! isset($claimedVariantIds[$byId->id])) {
                    $matched = $byId;
                }
            }

            $attrs = [
                'sku' => $variantData['sku'] !== '' ? $variantData['sku'] : null,
                'price' => (float) ($variantData['price'] ?? 0),
                'compare_price' => isset($variantData['compare_price']) && $variantData['compare_price'] !== ''
                    ? (float) $variantData['compare_price']
                    : null,
                'cost' => isset($variantData['cost']) ? (float) $variantData['cost'] : (float) ($product->cost ?: 0),
            ];

            if ($matched !== null) {
                $matched->update($attrs);
                $variant = $matched;
            } else {
                $variant = ProductVariant::create(array_merge($attrs, [
                    'product_id' => $product->id,
                    'name' => $this->displayName($variantData['options']),
                ]));
            }

            $variant->syncAttributeValues($submittedCombos[$index]);
            $claimedVariantIds[$variant->id] = true;
            $resultVariants->push($variant);
        }

        // 4. Combinations no longer submitted — remove only if safe.
        $warnings = [];
        foreach ($existingVariants as $existing) {
            if (isset($claimedVariantIds[$existing->id])) {
                continue;
            }

            $hasChannelListing = ProductVariantChannelListing::withoutTenancy(
                fn () => ProductVariantChannelListing::query()->where('product_variant_id', $existing->id)->exists()
            );
            $hasInventoryLink = VariantInventoryLink::withoutOrganizationTenancy(
                fn () => VariantInventoryLink::query()->where('product_variant_id', $existing->id)->exists()
            );

            if ($hasChannelListing || $hasInventoryLink) {
                $warnings[] = "Kept \"{$existing->getDisplayName()}\" — it's linked to a platform listing or inventory item and can't be removed by regenerating variants.";
                continue;
            }

            $existing->delete(); // soft delete — ProductVariant already uses SoftDeletes.
        }

        // 5. Orphan cleanup — only options/values nothing still references.
        ProductAttribute::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $submittedAttributeIds)
            ->get()
            ->each(function (ProductAttribute $attribute) {
                $stillUsed = $attribute->values()->whereHas('variants')->exists();
                if (! $stillUsed) {
                    $attribute->delete();
                }
            });

        return ['variants' => $resultVariants, 'warnings' => $warnings];
    }

    /**
     * @param  array<int, array{name?: mixed, values?: mixed}>  $options
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    private function validateOptions(array $options): array
    {
        $normalized = [];
        $seenSlugs = [];

        foreach ($options as $index => $option) {
            $name = trim((string) ($option['name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(["options.{$index}.name" => 'Option name is required.']);
            }

            $slug = Str::slug($name);
            if (isset($seenSlugs[$slug])) {
                throw ValidationException::withMessages(["options.{$index}.name" => "Option \"{$name}\" is already defined for this product."]);
            }
            $seenSlugs[$slug] = true;

            $values = collect($option['values'] ?? [])
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn ($v) => $v !== '')
                ->values();

            if ($values->isEmpty()) {
                throw ValidationException::withMessages(["options.{$index}.values" => "Option \"{$name}\" needs at least one value."]);
            }

            $seenValueSlugs = [];
            foreach ($values as $value) {
                $valueSlug = Str::slug($value);
                if (isset($seenValueSlugs[$valueSlug])) {
                    throw ValidationException::withMessages(["options.{$index}.values" => "Value \"{$value}\" is duplicated under option \"{$name}\"."]);
                }
                $seenValueSlugs[$valueSlug] = true;
            }

            $normalized[] = ['name' => $name, 'values' => $values->all()];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{id?: mixed, sku?: mixed, price?: mixed, compare_price?: mixed, cost?: mixed, options?: mixed}>  $variants
     * @param  array<int, string>  $optionNames
     * @return array<int, array{id: ?string, sku: string, price: mixed, compare_price: mixed, cost: mixed, options: array<string, string>}>
     */
    private function validateVariants(array $variants, array $optionNames): array
    {
        $normalized = [];

        foreach ($variants as $index => $variant) {
            $variantOptions = $variant['options'] ?? [];

            if (! is_array($variantOptions)) {
                throw ValidationException::withMessages(["variants.{$index}.options" => 'Each variant needs an option value for every product option.']);
            }

            foreach ($optionNames as $name) {
                $value = trim((string) ($variantOptions[$name] ?? ''));
                if ($value === '') {
                    throw ValidationException::withMessages(["variants.{$index}.options" => "This variant is missing a value for \"{$name}\"."]);
                }
            }

            // A variant may not carry values for options that no longer exist.
            $extra = array_diff(array_keys($variantOptions), $optionNames);
            if ($extra !== []) {
                throw ValidationException::withMessages(["variants.{$index}.options" => 'This variant references an option that no longer exists on the product.']);
            }

            $normalized[] = [
                'id' => empty($variant['id']) ? null : (string) $variant['id'],
                'sku' => trim((string) ($variant['sku'] ?? '')),
                'price' => $variant['price'] ?? 0,
                'compare_price' => $variant['compare_price'] ?? null,
                'cost' => $variant['cost'] ?? null,
                'options' => $variantOptions,
            ];
        }

        return $normalized;
    }

    /** @param array<string, string> $options */
    private function displayName(array $options): string
    {
        return collect($options)->map(fn ($value, $name) => "{$name}: {$value}")->implode(' / ');
    }
}
