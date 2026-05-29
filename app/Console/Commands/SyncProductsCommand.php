<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncProductsCommand extends Command
{
    protected $signature = 'sync:products {--store= : Store ID} {--platform= : Platform name}';
    protected $description = 'Sync products from connected platforms';

    public function handle(SyncService $syncService): int
    {
        $storeId = $this->option('store');
        $platformName = $this->option('platform');

        // Get stores to sync
        if ($storeId) {
            $stores = Store::where('id', $storeId)->get();
            if ($stores->isEmpty()) {
                $this->error("Store not found: $storeId");
                return Command::FAILURE;
            }
        } else {
            $stores = Store::all();
        }

        if ($stores->isEmpty()) {
            $this->info('No stores to sync');
            return Command::SUCCESS;
        }

        $this->info("Syncing products across {$stores->count()} store(s)...");

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($stores as $store) {
            // Get connections for this store
            if ($platformName) {
                $connections = $store->connections()
                    ->where('platform', $platformName)
                    ->get();
            } else {
                $connections = $store->connections()->get();
            }

            if ($connections->isEmpty()) {
                $this->warn("  ✗ [{$store->name}] No connections found");
                continue;
            }

            foreach ($connections as $connection) {
                $this->line("  → [{$connection->platform}] {$store->name}");

                try {
                    // Sync products using the connection's platform
                    $result = $syncService->syncProducts($store, $connection->platform);

                    $this->line(
                        "    Done — created: {$result['created']}  updated: {$result['updated']}  failed: {$result['failed']}"
                    );

                    $totalCreated += $result['created'];
                    $totalUpdated += $result['updated'];
                    $totalFailed += $result['failed'];

                } catch (\Exception $e) {
                    $this->error("    Exception — {$e->getMessage()}");
                }
            }
        }

        $this->newLine();

        if ($totalFailed === 0) {
            $this->info("All syncs completed successfully! ✅");
            $this->info("Total — created: $totalCreated  updated: $totalUpdated");
            return Command::SUCCESS;
        } else {
            $this->warn("Some syncs failed — check the logs.");
            $this->info("Total — created: $totalCreated  updated: $totalUpdated  failed: $totalFailed");
            return Command::FAILURE;
        }
    }
}