<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\ProductCleanupSafetyService;
use Illuminate\Console\Command;

class CatalogCleanupPreviewCommand extends Command
{
    protected $signature = 'catalog:cleanup-preview
        {--store= : Store ULID to preview (required)}
        {--connection= : Only preview products imported from this PlatformConnection ULID}';

    protected $description = 'Read-only: report which of a store\'s (optionally connection-scoped) products can be safely purged, and why the rest cannot.';

    public function handle(ProductCleanupSafetyService $safety): int
    {
        $storeId = (string) $this->option('store');

        if ($storeId === '') {
            $this->error('--store is required.');

            return self::FAILURE;
        }

        $products = Product::withoutTenancy(function () use ($storeId) {
            $query = Product::query()->where('store_id', $storeId);

            $connectionId = $this->option('connection');
            if ($connectionId !== null) {
                $query->whereHas('channelListings', fn ($q) => $q->where('platform_connection_id', $connectionId));
            }

            return $query->get();
        });

        if ($products->isEmpty()) {
            $this->info('No matching products found.');

            return self::SUCCESS;
        }

        $checks = $safety->checkMany($products);

        $rows = $checks->map(fn (array $c) => [
            $c['product_id'],
            $c['sku'] ?? '—',
            $c['name'],
            $c['can_purge'] ? 'yes' : 'no',
            $c['blockers'] === [] ? '—' : implode(' | ', $c['blockers']),
        ]);

        $this->table(['Product ID', 'SKU', 'Name', 'Can purge', 'Blockers'], $rows);

        $allowed = $checks->where('can_purge', true)->count();
        $blocked = $checks->where('can_purge', false)->count();
        $this->info("{$allowed} purgeable, {$blocked} blocked, out of {$checks->count()} total.");

        return self::SUCCESS;
    }
}
