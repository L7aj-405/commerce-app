<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Sync\StockSyncService;
use Illuminate\Console\Command;

class SyncStockCommand extends Command
{
    protected $signature = 'sync:stock {--store= : Store ID to sync}';

    protected $description = 'Sync product stock quantities from connected platforms';

    public function handle(StockSyncService $stockSyncService): int
    {
        $storeId = $this->option('store');

        $stores = $storeId
            ? Store::where('id', $storeId)->get()
            : Store::all();

        if ($stores->isEmpty()) {
            $this->error($storeId ? "Store not found: $storeId" : 'No stores found');
            return Command::FAILURE;
        }

        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($stores as $store) {
            $this->line("Processing store: {$store->name}");

            $connections = $store->connections()->where('status', 'active')->get();

            if ($connections->isEmpty()) {
                $this->warn("  No active connections for {$store->name}");
                continue;
            }

            foreach ($connections as $connection) {
                $this->line("  → [{$connection->platform}]");

                try {
                    $result = $stockSyncService->syncStockFromPlatform($store, $connection->platform);

                    $totalUpdated += $result['updated'];
                    $totalFailed += $result['failed'];

                    $this->line("    Done — updated: {$result['updated']}  failed: {$result['failed']}");
                } catch (\Exception $e) {
                    $totalFailed++;
                    $this->error("    Error: {$e->getMessage()}");
                }
            }
        }

        $this->newLine();
        $this->info("Done — updated: $totalUpdated  failed: $totalFailed");

        return $totalFailed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
