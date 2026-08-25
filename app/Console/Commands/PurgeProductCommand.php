<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\ProductCleanupSafetyService;
use App\Services\Catalog\ProductCleanupService;
use Illuminate\Console\Command;

class PurgeProductCommand extends Command
{
    protected $signature = 'catalog:purge-product {product_id : The Product ULID to purge}
        {--dry-run : Preview whether the product can be purged without deleting anything (this is also the default)}
        {--apply : Actually perform the purge}';

    protected $description = 'Permanently delete one imported product and every safe-to-delete dependent row (variants, channel listings, inventory links). Blocked by any order/return/transfer/ledger history. Defaults to dry-run.';

    public function handle(ProductCleanupService $service, ProductCleanupSafetyService $safety): int
    {
        $productId = (string) $this->argument('product_id');
        $product = Product::withoutTenancy(fn () => Product::query()->find($productId));

        if ($product === null) {
            $this->error("Product {$productId} not found.");

            return self::FAILURE;
        }

        $dryRun = ! $this->option('apply');

        if ($dryRun) {
            $check = $safety->check($product);

            if (! $check['can_purge']) {
                $this->warn("Product {$product->id} (sku: {$product->sku}) cannot be purged:");
                foreach ($check['blockers'] as $blocker) {
                    $this->line("  - {$blocker}");
                }

                return self::SUCCESS;
            }

            $this->info("Product {$product->id} (sku: {$product->sku}) can be safely purged.");
            $this->comment('Dry run only — pass --apply to actually perform the purge.');

            return self::SUCCESS;
        }

        $result = $service->purgeOne($product);

        if (! $result['purged']) {
            $this->warn("Product {$productId} was NOT purged:");
            foreach ($result['blockers'] as $blocker) {
                $this->line("  - {$blocker}");
            }

            return self::FAILURE;
        }

        $this->info("Product {$productId} purged.");

        return self::SUCCESS;
    }
}
