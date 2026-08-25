<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\ProductDiagnosticService;
use Illuminate\Console\Command;

class RepairProductCommand extends Command
{
    protected $signature = 'catalog:repair-product {product_id : The Product ULID to repair}
        {--dry-run : Preview what would change without writing anything (this is also the default)}
        {--apply : Actually perform the safe repair actions}';

    protected $description = 'Conservatively repair a single product\'s safe, unambiguous catalog data corruption. Defaults to dry-run; pass --apply to write changes. Never deletes external mappings, only archives.';

    public function handle(ProductDiagnosticService $diagnostics): int
    {
        $productId = (string) $this->argument('product_id');
        $product = Product::query()->find($productId);

        if ($product === null) {
            $this->error("Product {$productId} not found.");

            return self::FAILURE;
        }

        $dryRun = ! $this->option('apply');

        $result = $diagnostics->repair($product, $dryRun);

        if ($result['actions_taken'] === []) {
            $this->info("No safe automatic repairs are applicable for product {$product->id} (sku: {$product->sku}).");
        } else {
            $verb = $dryRun ? 'Would apply' : 'Applied';
            foreach ($result['actions_taken'] as $action) {
                $this->line("{$verb}: {$action}");
            }

            if ($dryRun) {
                $this->comment('Dry run only — pass --apply to actually perform these changes.');
            }
        }

        if ($result['unresolved_issues'] !== []) {
            $this->warn(count($result['unresolved_issues']) . ' issue(s) require manual review or a re-publish/re-sync (not auto-fixable):');
            foreach ($result['unresolved_issues'] as $issue) {
                $this->line("  [{$issue['severity']}] {$issue['code']}: {$issue['message']}");
            }
        }

        return self::SUCCESS;
    }
}
