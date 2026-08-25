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
use Illuminate\Support\Facades\DB;
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
 *
 * The wizard submission is always the active source of truth: any
 * previously-saved option value or variant that isn't resubmitted is
 * removed from the active set (hard-deleted when nothing still points to
 * it, archived via `is_active`/soft-delete otherwise) — old Shopify/WooCommerce
 * import data never resurrects something the user explicitly removed.
 */
class ProductVariantWizardService
{
    /**
     * Archive every active option/variant for a product with nothing left to
     * keep — used when a product becomes simple, whether via the Product
     * Edit form (variable -> simple) or an external sync that reports the
     * remote product no longer has meaningful options/variants. Equivalent
     * to sync($product, [], []): every active ProductVariant is soft-deleted
     * (never hard-deleted, so a variant still carrying a
     * ProductVariantChannelListing or inventory link keeps its history),
     * every ProductAttributeValue is retired (hard-deleted when safe,
     * flipped inactive when a still-referenced archived variant protects
     * it), and any now-orphaned ProductAttribute is cleaned up the same way
     * sync() already does for a fully-resubmitted-empty option list.
     *
     * @return array{variants: Collection<int, ProductVariant>, warnings: array<int, string>}
     */
    public function archiveAll(Product $product): array
    {
        return $this->sync($product, [], []);
    }

    /**
     * @param  array<int, array{name: string, values: array<int, string>}>  $options
     * @param  array<int, array{id?: ?string, sku?: ?string, price?: mixed, compare_price?: mixed, cost?: mixed, status?: ?string, options: array<string, string>}>  $variants
     * @param  bool  $regenerateSkus  When true, every active variant's SKU is regenerated, even if it already has one.
     * @return array{variants: Collection<int, ProductVariant>, warnings: array<int, string>}
     */
    public function sync(Product $product, array $options, array $variants, bool $regenerateSkus = false): array
    {
        $options = $this->validateOptions($options);
        $optionNames = collect($options)->pluck('name')->all();

        $variants = $this->validateVariants($variants, $optionNames);

        // 1. Upsert option definitions + values, building name+value -> id.
        // Every submitted value is (re)activated here — this is what lets a
        // user re-add a value they'd previously removed without creating a
        // duplicate row under the same option.
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
                $attributeValue->update(['position' => $valuePosition, 'is_active' => true]);
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

        // 3. Match against existing (non-deleted, i.e. active) variants by
        // the same canonical combination.
        $existingVariants = $product->variants()->with('attributeValues')->get();
        $existingByCombo = [];
        foreach ($existingVariants as $existing) {
            $ids = $existing->attributeValues->pluck('id')->sort()->values()->all();
            $existingByCombo[implode(',', $ids)] = $existing;
        }

        $claimedVariantIds = [];
        $resultVariants = collect();
        $reservedSkus = [];
        $baseSku = $this->baseSku($product);

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

            $orderedCombo = collect($optionNames)->mapWithKeys(fn ($name) => [$name => $variantData['options'][$name]])->all();

            $needsSku = $regenerateSkus || $variantData['sku'] === '';
            $sku = $needsSku
                ? $this->generateUniqueSku($product, $baseSku, $orderedCombo, $matched?->id, $reservedSkus)
                : $variantData['sku'];
            $reservedSkus[$sku] = true;

            $attrs = [
                'sku' => $sku,
                'price' => (float) ($variantData['price'] ?? 0),
                'compare_price' => isset($variantData['compare_price']) && $variantData['compare_price'] !== ''
                    ? (float) $variantData['compare_price']
                    : null,
                'cost' => isset($variantData['cost']) ? (float) $variantData['cost'] : (float) ($product->cost ?: 0),
            ];

            if ($matched === null) {
                // No active combo/id match — but an ARCHIVED variant (soft-
                // deleted by a prior archiveAll()/sync() call, e.g. a
                // variable-to-simple-and-back round trip) may already sit on
                // this exact sku. product_variants has a unique
                // (product_id, sku) index that soft-delete does not exempt,
                // so blindly creating a new row here would throw a DB
                // integrity violation. Restoring the archived row instead
                // reuses its original id, which also transparently
                // reconnects any ProductVariantChannelListing left over from
                // before it was archived.
                $trashed = $product->variants()->onlyTrashed()->where('sku', $sku)->first();

                if ($trashed !== null && ! isset($claimedVariantIds[$trashed->id])) {
                    $trashed->restore();
                    $matched = $trashed;
                }
            }

            if ($matched !== null) {
                $matched->update($attrs);
                $variant = $matched;
            } else {
                $variant = ProductVariant::create(array_merge($attrs, [
                    'product_id' => $product->id,
                    // Display name is cosmetic only — the real identity and
                    // the UI's combination column both come from the
                    // canonical option-value pivot, never from this string.
                    'name' => 'Variant ' . ($index + 1),
                ]));
            }

            $variant->syncAttributeValues($submittedCombos[$index]);
            $claimedVariantIds[$variant->id] = true;
            $resultVariants->push($variant);
        }

        // 4. Combinations no longer submitted — always removed from the
        // active set. Safe ones (no external listing/inventory link) are
        // simply soft-deleted; unsafe ones are ALSO soft-deleted (archived)
        // rather than left active — ProductVariant's SoftDeletes column is
        // the archive flag here, and archiving never touches the listing
        // row itself, so the external mapping survives untouched for a
        // future re-link/publish decision. The one thing that must never
        // happen is leaving a removed combination sitting in the active
        // variants list, which is exactly the bug this fixes.
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
                $warnings[] = "Archived \"{$existing->getDisplayName()}\" — it's linked to a platform listing or inventory item, so it was kept (inactive) instead of deleted.";
            }

            $existing->delete(); // soft delete — this IS the archive: excluded from every active query going forward.
        }

        // 5. Values the user removed from a still-present option. Hard
        // delete when nothing protected still points to it; otherwise flip
        // is_active off so it never comes back as an active option — but
        // the row (and any archived variant's pivot link to it) survives.
        foreach ($submittedValueIdsByAttribute as $attributeId => $keepIds) {
            ProductAttributeValue::query()
                ->where('attribute_id', $attributeId)
                ->where('is_active', true)
                ->whereNotIn('id', $keepIds)
                ->get()
                ->each(fn (ProductAttributeValue $value) => $this->retireValue($value));
        }

        // 6. Orphan cleanup — whole options the user removed entirely this
        // time. Same protected-reference rule as step 5: only hard-delete
        // the attribute (which cascades to its values) when nothing still
        // references any of its values; otherwise archive the values and
        // leave the attribute row in place.
        ProductAttribute::query()
            ->where('product_id', $product->id)
            ->whereNotIn('id', $submittedAttributeIds)
            ->get()
            ->each(function (ProductAttribute $attribute) {
                $valueIds = $attribute->values()->pluck('id');
                $stillReferenced = $valueIds->isNotEmpty() && DB::table('product_variant_attribute_values')
                    ->whereIn('product_attribute_value_id', $valueIds)
                    ->exists();

                if ($stillReferenced) {
                    ProductAttributeValue::query()->where('attribute_id', $attribute->id)->update(['is_active' => false]);
                } else {
                    $attribute->delete();
                }
            });

        return ['variants' => $resultVariants, 'warnings' => $warnings];
    }

    /**
     * A value is safe to hard-delete unless an archived (soft-deleted)
     * variant that still has an external channel listing or inventory link
     * is the reason it's still referenced in the pivot — deleting it there
     * would cascade-delete that pivot row and lose the archived variant's
     * only record of what it was. Anything else (unreferenced, or only
     * referenced by a variant that was safely deleted outright) is hard-deleted.
     */
    private function retireValue(ProductAttributeValue $value): void
    {
        $referencingVariantIds = DB::table('product_variant_attribute_values')
            ->where('product_attribute_value_id', $value->id)
            ->pluck('product_variant_id');

        $protected = $referencingVariantIds->isNotEmpty() && (
            ProductVariantChannelListing::withoutTenancy(
                fn () => ProductVariantChannelListing::query()->whereIn('product_variant_id', $referencingVariantIds)->exists()
            )
            || VariantInventoryLink::withoutOrganizationTenancy(
                fn () => VariantInventoryLink::query()->whereIn('product_variant_id', $referencingVariantIds)->exists()
            )
        );

        if ($protected) {
            $value->update(['is_active' => false]);
        } else {
            $value->delete();
        }
    }

    private function baseSku(Product $product): string
    {
        if (! empty($product->sku)) {
            return strtoupper((string) $product->sku);
        }

        $slug = strtoupper(Str::slug((string) $product->name, '-'));

        return $slug !== '' ? $slug : strtoupper(substr($product->id, 0, 8));
    }

    private function skuFragment(string $value): string
    {
        $fragment = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return $fragment !== '' ? $fragment : 'X';
    }

    /**
     * @param  array<string, string>  $orderedCombo  option name => value, in canonical option-position order
     * @param  array<string, bool>  $reserved  SKUs already claimed earlier in this same sync() call, by reference
     */
    private function generateUniqueSku(Product $product, string $base, array $orderedCombo, ?string $ignoreVariantId, array &$reserved): string
    {
        $fragment = collect($orderedCombo)->map(fn ($value) => $this->skuFragment((string) $value))->implode('-');
        $candidate = trim($base . '-' . $fragment, '-');
        $sku = $candidate;
        $suffix = 2;

        while (
            isset($reserved[$sku])
            || ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('sku', $sku)
                ->when($ignoreVariantId, fn ($q) => $q->whereKeyNot($ignoreVariantId))
                ->exists()
        ) {
            $sku = "{$candidate}-{$suffix}";
            $suffix++;
        }

        return $sku;
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
}
