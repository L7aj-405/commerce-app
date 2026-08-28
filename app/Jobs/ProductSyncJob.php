<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformConnection;
use App\Models\ProductSyncResult;
use App\Models\Store;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Imports one PlatformConnection's catalog into the store and records the
 * outcome on its own ProductSyncResult row. One job per connection so a
 * slow/failing platform never blocks the others — the HTTP endpoint that
 * dispatches this (ProductSyncController::startSync) returns immediately,
 * before any of these run. Product/variant matching (external id first,
 * then unambiguous same-store SKU fallback, never cross-store) is entirely
 * ProductSyncService::syncFromPlatform's existing responsibility — this job
 * is only the queue wrapper + result bookkeeping around it.
 */
class ProductSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public readonly string $resultId) {}

    public function handle(ProductSyncService $syncService): void
    {
        $result = ProductSyncResult::withoutTenancy(
            fn () => ProductSyncResult::query()->find($this->resultId),
        );

        if ($result === null) {
            return;
        }

        $result->update(['status' => ProductSyncResult::STATUS_RUNNING]);

        $store = Store::query()->find($result->store_id);
        $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($result->platform_connection_id));

        if ($store === null || $connection === null) {
            $this->finish($result, [
                'status' => ProductSyncResult::STATUS_FAILED,
                'message' => 'Store or connection no longer exists.',
            ]);

            return;
        }

        try {
            $outcome = $syncService->syncFromPlatform($store, $connection->platform);

            $failed = (int) ($outcome['failed'] ?? 0);
            $created = (int) ($outcome['created'] ?? 0);
            $updated = (int) ($outcome['updated'] ?? 0);

            $this->finish($result, [
                'status' => $failed > 0 && $created === 0 && $updated === 0
                    ? ProductSyncResult::STATUS_FAILED
                    : ProductSyncResult::STATUS_SUCCEEDED,
                'created_count' => $created,
                'updated_count' => $updated,
                'failed_item_count' => $failed,
                'message' => $failed > 0 ? "{$failed} item(s) failed to import." : null,
            ]);
        } catch (Throwable $e) {
            $this->finish($result, [
                'status' => ProductSyncResult::STATUS_FAILED,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @param array{status: string, message: ?string, created_count?: int, updated_count?: int, failed_item_count?: int} $outcome */
    private function finish(ProductSyncResult $result, array $outcome): void
    {
        $result->update([
            'status' => $outcome['status'],
            'created_count' => $outcome['created_count'] ?? 0,
            'updated_count' => $outcome['updated_count'] ?? 0,
            'failed_item_count' => $outcome['failed_item_count'] ?? 0,
            'message' => $outcome['message'],
        ]);

        $result->batch?->refreshCounts();
    }
}
