<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Services\Catalog\ProductCleanupSafetyService;
use App\Services\Catalog\ProductCleanupService;
use Illuminate\Console\Command;

class PurgeImportedProductsCommand extends Command
{
    protected $signature = 'catalog:purge-imported
        {--connection= : PlatformConnection ULID — only products with a channel listing for this connection}
        {--dry-run : Preview without deleting anything (this is also the default)}
        {--apply : Actually perform the purge on every safe product}';

    protected $description = 'Bulk-purge every safe-to-delete product imported from a platform connection. Blocked products are skipped and reported, never partially deleted. Defaults to dry-run.';

    public function handle(ProductCleanupService $service, ProductCleanupSafetyService $safety): int
    {
        $connectionId = (string) $this->option('connection');

        if ($connectionId === '') {
            $this->error('--connection is required.');

            return self::FAILURE;
        }

        $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($connectionId));

        if ($connection === null) {
            $this->error("Platform connection {$connectionId} not found.");

            return self::FAILURE;
        }

        $products = Product::withoutTenancy(
            fn () => Product::query()
                ->whereHas('channelListings', fn ($q) => $q->where('platform_connection_id', $connectionId))
                ->get()
        );

        if ($products->isEmpty()) {
            $this->info('No products are linked to this connection.');

            return self::SUCCESS;
        }

        $dryRun = ! $this->option('apply');

        if ($dryRun) {
            $checks = $safety->checkMany($products);

            foreach ($checks as $check) {
                if ($check['can_purge']) {
                    $this->line("Would purge: {$check['product_id']} (sku: {$check['sku']})");
                } else {
                    $this->line("Would skip: {$check['product_id']} (sku: {$check['sku']}) — " . implode(' | ', $check['blockers']));
                }
            }

            $allowed = $checks->where('can_purge', true)->count();
            $this->comment("Dry run only — {$allowed}/{$checks->count()} would be purged. Pass --apply to actually perform the purge.");

            return self::SUCCESS;
        }

        $results = $service->purge($products);

        foreach ($results as $result) {
            if ($result['purged']) {
                $this->info("Purged: {$result['product_id']}");
            } else {
                $this->warn("Skipped: {$result['product_id']} — " . implode(' | ', $result['blockers']));
            }
        }

        $purged = collect($results)->where('purged', true)->count();
        $this->info("{$purged}/" . count($results) . ' products purged.');

        return self::SUCCESS;
    }
}
