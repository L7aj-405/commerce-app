<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Sync\ProductSyncService;
use Illuminate\Console\Command;

class SyncAllProductsCommand extends Command
{
    protected $signature = 'sync:all
        {--store= : Specific store ULID (omit for all stores)}';

    protected $description = 'Sync products from all active platform connections';

    public function handle(ProductSyncService $syncService): int
    {
        $stores = Store::query()
            ->when($this->option('store'), fn ($q) => $q->where('id', $this->option('store')))
            ->get();

        if ($stores->isEmpty()) {
            $this->error('No stores found.');
            return self::FAILURE;
        }

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalFailed  = 0;

        foreach ($stores as $store) {
            $connections = $store->connections()->where('status', 'active')->get();

            if ($connections->isEmpty()) {
                $this->line("<fg=gray>  {$store->name}: no active connections, skipping</>");
                continue;
            }

            $this->line("\n<fg=cyan>Store:</> {$store->name}");
            $bar = $this->output->createProgressBar($connections->count());
            $bar->start();

            foreach ($connections as $connection) {
                $label = $connection->label ?: ucfirst($connection->platform);

                try {
                    $result = $syncService->syncFromPlatform($store, $connection->platform);

                    $totalCreated += $result['created'] ?? 0;
                    $totalUpdated += $result['updated'] ?? 0;
                    $totalFailed  += $result['failed']  ?? 0;
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $this->newLine();
                    $this->warn("  {$label}: " . $e->getMessage());
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Created', $totalCreated],
                ['Updated', $totalUpdated],
                ['Failed',  $totalFailed],
            ]
        );

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
