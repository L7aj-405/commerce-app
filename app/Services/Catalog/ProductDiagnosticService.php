<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductAttributeValue;

/**
 * Phase S7 — read-only diagnostics + conservative repair for catalog data
 * corruption on a single product. Every check here is something that can
 * genuinely happen from external sync/import edge cases (a product reverted
 * from variable to simple without its options being retired, two local
 * listings pointing at the same remote product, a Shopify variant that was
 * never given inventory tracking, etc.) — never a hypothetical.
 *
 * repair() only ever performs the ONE safe, fully-local, reversible action
 * (archiving stale options/variants on a simple product, via the same
 * ProductVariantWizardService::archiveAll() the Product Edit "switch to
 * simple" flow already uses). Everything else is reported but left for a
 * human/re-publish to resolve — attaching a missing external_inventory_item_id
 * or backfilling Shopify default-variant metadata both require a real API
 * call, which doesn't belong in an offline repair command.
 *
 * Duplicate products/listings are deliberately NOT diagnosed here: the schema
 * already makes them impossible (products_store_id_sku_unique on
 * (store_id, sku), pcl_product_connection_unique on
 * (product_id, platform_connection_id), and pcl_connection_external_unique
 * on (platform_connection_id, external_product_id) — see
 * database/migrations/2026_08_18_000003_create_catalog_channel_listings.php).
 * A runtime check for a state the database rejects at insert time would be
 * dead code.
 */
class ProductDiagnosticService
{
    public function __construct(private readonly ProductVariantWizardService $wizard) {}

    /** @return array<int, array{code: string, severity: string, message: string}> */
    public function diagnose(Product $product): array
    {
        $product->loadMissing([
            'variants' => fn ($q) => $q->withTrashed(),
            'variants.attributeValues' => fn ($q) => $q->active(),
            'variants.channelListings.connection:id,platform,label',
            'channelListings.connection:id,platform,label',
            'attributes.values',
        ]);

        $issues = [];

        if ($product->isSimple()) {
            foreach ($this->simpleProductGhostIssues($product) as $issue) {
                $issues[] = $issue;
            }
        }

        if ($product->isVariable()) {
            foreach ($this->missingPivotIssues($product) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($this->shopifyMetadataIssues($product) as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * Archives every active option/variant left over on a simple product —
     * the only automatic fix this tool performs. Everything else diagnose()
     * reports is returned unresolved for manual/re-publish follow-up.
     *
     * @return array{dry_run: bool, actions_taken: array<int, string>, unresolved_issues: array<int, array{code: string, severity: string, message: string}>}
     */
    public function repair(Product $product, bool $dryRun = true): array
    {
        $issues = $this->diagnose($product);
        $actionsTaken = [];

        $ghostCodes = ['ghost_variants_on_simple_product', 'active_options_on_simple_product'];
        $hasGhostState = collect($issues)->contains(fn ($issue) => in_array($issue['code'], $ghostCodes, true));

        if ($hasGhostState) {
            $actionsTaken[] = 'archive_simple_product_options_and_variants';

            if (! $dryRun) {
                $this->wizard->archiveAll($product);
            }
        }

        $unresolved = collect($issues)
            ->reject(fn ($issue) => in_array($issue['code'], $ghostCodes, true))
            ->values()
            ->all();

        return [
            'dry_run' => $dryRun,
            'actions_taken' => $actionsTaken,
            'unresolved_issues' => $unresolved,
        ];
    }

    /** @return array<int, array{code: string, severity: string, message: string}> */
    private function simpleProductGhostIssues(Product $product): array
    {
        $issues = [];

        $activeVariantCount = $product->variants->whereNull('deleted_at')->count();
        if ($activeVariantCount > 0) {
            $issues[] = [
                'code' => 'ghost_variants_on_simple_product',
                'severity' => 'error',
                'message' => "{$activeVariantCount} active ProductVariant row(s) exist on a product typed as simple — likely left over from a variable-to-simple switch that didn't archive them.",
            ];
        }

        $activeOptionValueCount = ProductAttributeValue::query()
            ->whereHas('attribute', fn ($q) => $q->where('product_id', $product->id))
            ->where('is_active', true)
            ->count();

        if ($activeOptionValueCount > 0) {
            $issues[] = [
                'code' => 'active_options_on_simple_product',
                'severity' => 'error',
                'message' => "{$activeOptionValueCount} active option value(s) still exist on a product typed as simple — the Product Edit options section should be empty for this product.",
            ];
        }

        return $issues;
    }

    /** @return array<int, array{code: string, severity: string, message: string}> */
    private function missingPivotIssues(Product $product): array
    {
        $issues = [];

        foreach ($product->variants->whereNull('deleted_at') as $variant) {
            if ($variant->attributeValues->isEmpty()) {
                $issues[] = [
                    'code' => 'variant_missing_option_pivots',
                    'severity' => 'error',
                    'message' => "Variant {$variant->id} (sku: {$variant->sku}) has no active canonical option values attached — it cannot be published/read correctly by the canonical mapper.",
                ];
            }
        }

        return $issues;
    }

    /** @return array<int, array{code: string, severity: string, message: string}> */
    private function shopifyMetadataIssues(Product $product): array
    {
        $issues = [];

        if ($product->isSimple()) {
            foreach ($product->channelListings as $listing) {
                if ($listing->connection?->platform !== 'shopify') {
                    continue;
                }

                $metadata = $listing->metadata ?? [];
                if (empty($metadata['default_variant_id']) || empty($metadata['default_inventory_item_id'])) {
                    $issues[] = [
                        'code' => 'missing_shopify_default_variant_metadata',
                        'severity' => 'warning',
                        'message' => "Shopify listing {$listing->id} is missing default_variant_id/default_inventory_item_id in metadata — stock push and re-publish for this simple product may fail until it's re-synced from Shopify.",
                    ];
                }
            }
        }

        foreach ($product->variants->whereNull('deleted_at') as $variant) {
            foreach ($variant->channelListings as $variantListing) {
                if ($variantListing->connection?->platform !== 'shopify') {
                    continue;
                }

                if (empty($variantListing->external_inventory_item_id)) {
                    $issues[] = [
                        'code' => 'missing_variant_inventory_item_id',
                        'severity' => 'warning',
                        'message' => "Variant {$variant->id} (sku: {$variant->sku}) Shopify listing {$variantListing->id} has no external_inventory_item_id — stock pushes for this variant will fail until it's re-synced from Shopify.",
                    ];
                }
            }
        }

        return $issues;
    }
}
