<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use App\Services\Sync\ProductSyncService;
use Illuminate\Console\Command;

class SyncVariantsCommand extends Command
{
    protected $signature = 'sync:variants
        {--store=    : Limit sync to a single store ID}
        {--platform= : Limit sync to a single platform (woocommerce, shopify, youcan)}';

    protected $description = 'Sync product variants from connected platforms';

    public function handle(ProductSyncService $syncService): int
    {
        $storeId      = $this->option('store');
        $platformName = $this->option('platform');

        $stores = $storeId
            ? Store::where('id', $storeId)->get()
            : Store::all();

        if ($stores->isEmpty()) {
            $this->error($storeId ? "Store not found: {$storeId}" : 'No stores found');
            return Command::FAILURE;
        }

        $totalSynced = 0;
        $totalFailed = 0;

        foreach ($stores as $store) {
            $this->line("Store: {$store->name}");

            $connectionsQuery = $store->connections()->where('status', 'active');

            if ($platformName !== null) {
                $connectionsQuery->where('platform', $platformName);
            }

            $connections = $connectionsQuery->get();

            if ($connections->isEmpty()) {
                $this->warn("  No active connections");
                continue;
            }

            foreach ($connections as $connection) {
                $this->line("  → [{$connection->platform}]");

                $connector = match ($connection->platform) {
                    'woocommerce' => new \App\Connectors\WooCommerceConnector($connection),
                    'shopify'     => new \App\Connectors\ShopifyConnector($connection),
                    'youcan'      => new \App\Connectors\YouCanConnector($connection),
                    default       => null,
                };

                if ($connector === null) {
                    $this->warn("    Skipping unknown platform: {$connection->platform}");
                    continue;
                }

                // Loop all variable products for this store
                $variableProducts = $store->products()
                    ->where('type', 'variable')
                    ->where('platform', $connection->platform)
                    ->get();

                if ($variableProducts->isEmpty()) {
                    $this->line("    No variable products found");
                    continue;
                }

                $this->line("    Found {$variableProducts->count()} variable product(s)");

                foreach ($variableProducts as $product) {
                    try {
                        $variantPage  = 1;
                        $perPage      = 100;
                        $variantCount = 0;

                        while (true) {
                            $variants = $connector->getProductVariants(
                                $product->external_id,
                                $variantPage,
                                $perPage
                            );

                            if (empty($variants)) {
                                break;
                            }

                            $syncService->createVariants($product, $variants);
                            $variantCount += count($variants);

                            if (count($variants) < $perPage) {
                                break;
                            }

                            $variantPage++;
                        }

                        $totalSynced++;
                        $this->line("    ✓ {$product->name} — {$variantCount} variant(s)");
                    } catch (\Exception $e) {
                        $totalFailed++;
                        $this->error("    ✗ {$product->name} — {$e->getMessage()}");
                    }
                }
            }
        }

        $this->newLine();
        $this->info("Done — products synced: {$totalSynced}  failed: {$totalFailed}");

        return $totalFailed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
