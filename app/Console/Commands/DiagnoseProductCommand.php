<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\ProductDiagnosticService;
use Illuminate\Console\Command;

class DiagnoseProductCommand extends Command
{
    protected $signature = 'catalog:diagnose-product {product_id : The Product ULID to diagnose}';

    protected $description = 'Report catalog data corruption for a single product (duplicates, ghost variants, missing canonical/Shopify metadata) without changing anything';

    public function handle(ProductDiagnosticService $diagnostics): int
    {
        $productId = (string) $this->argument('product_id');
        $product = Product::query()->find($productId);

        if ($product === null) {
            $this->error("Product {$productId} not found.");

            return self::FAILURE;
        }

        $issues = $diagnostics->diagnose($product);

        if ($issues === []) {
            $this->info("No issues found for product {$product->id} (sku: {$product->sku}).");

            return self::SUCCESS;
        }

        $this->warn(count($issues) . " issue(s) found for product {$product->id} (sku: {$product->sku}):");

        foreach ($issues as $issue) {
            $this->line("  [{$issue['severity']}] {$issue['code']}: {$issue['message']}");
        }

        return self::SUCCESS;
    }
}
