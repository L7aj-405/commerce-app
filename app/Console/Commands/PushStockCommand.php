<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use App\Services\Sync\ProductPushService;
use Illuminate\Console\Command;

class PushStockCommand extends Command
{
    protected $signature = 'push:stock
        {--store= : Push stock for a specific store ID}
        {--platform= : Restrict to a specific platform (woocommerce/shopify/youcan)}';

    protected $description = 'Push local stock quantities to connected platforms';

    public function handle(ProductPushService $pushService): int
    {
        $storeId  = $this->option('store');
        $platform = $this->option('platform');

        $storeQuery = Store::query();

        if ($storeId !== null) {
            $storeQuery->where('id', $storeId);
        }

        $stores = $storeQuery->get();

        if ($stores->isEmpty()) {
            $this->warn('No stores found.');
            return Command::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;

        foreach ($stores as $store) {
            $this->line("Store: {$store->name}");

            $productQuery = $store->products()
                ->whereHas('channelListings', function ($query) use ($platform): void {
                    if ($platform !== null) {
                        $query->whereHas('connection', fn ($connection) => $connection->where('platform', $platform));
                    }
                });

            $products = $productQuery->with('stocks')->get();

            if ($products->isEmpty()) {
                $this->line("  No pushable products found.");
                continue;
            }

            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            foreach ($products as $product) {
                $results = $pushService->pushStock($product, $platform);

                foreach ($results as $result) {
                    $result['success'] ? $pushed++ : $failed++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->table(
            ['Pushed', 'Failed'],
            [[$pushed, $failed]],
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
