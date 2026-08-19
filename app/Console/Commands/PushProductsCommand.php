<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use App\Services\Sync\ProductPushService;
use Illuminate\Console\Command;

class PushProductsCommand extends Command
{
    protected $signature = 'push:products
        {--store= : Push products for a specific store ID}
        {--platform= : Restrict to a specific platform (woocommerce/shopify/youcan)}
        {--product= : Push a single product by ID}';

    protected $description = 'Push local product changes to connected platforms';

    public function handle(ProductPushService $pushService): int
    {
        $storeId    = $this->option('store');
        $platform   = $this->option('platform');
        $productId  = $this->option('product');

        // Single product mode
        if ($productId !== null) {
            $product = Product::find($productId);

            if ($product === null) {
                $this->error("Product [{$productId}] not found.");
                return Command::FAILURE;
            }

            $this->info("Pushing product: {$product->name}");
            $results = $pushService->pushProduct($product, $platform);

            foreach ($results as $result) {
                $this->line(
                    $result['success']
                        ? "  ✓ [{$result['platform']}] {$result['message']}"
                        : "  ✗ [{$result['platform']}] {$result['message']}"
                );
            }

            return Command::SUCCESS;
        }

        // Store-filtered or all-stores mode
        $storeQuery = Store::query();

        if ($storeId !== null) {
            $storeQuery->where('id', $storeId);
        }

        $stores = $storeQuery->get();

        if ($stores->isEmpty()) {
            $this->warn('No stores found.');
            return Command::SUCCESS;
        }

        $pushed  = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($stores as $store) {
            $this->line("Store: {$store->name}");

            $productQuery = $store->products()
                ->whereHas('channelListings', function ($query) use ($platform): void {
                    if ($platform !== null) {
                        $query->whereHas('connection', fn ($connection) => $connection->where('platform', $platform));
                    }
                });

            $products = $productQuery->get();

            if ($products->isEmpty()) {
                $this->line("  No pushable products found.");
                continue;
            }

            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            foreach ($products as $product) {
                $results = $pushService->pushProduct($product, $platform);

                foreach ($results as $result) {
                    if ($result['error'] === 'missing_external_id') {
                        $skipped++;
                    } elseif ($result['success']) {
                        $pushed++;
                    } else {
                        $failed++;
                    }
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->table(
            ['Pushed', 'Failed', 'Skipped'],
            [[$pushed, $failed, $skipped]],
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
