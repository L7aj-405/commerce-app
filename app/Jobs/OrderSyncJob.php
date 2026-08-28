<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OrderSyncResult;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Services\Sync\OrderSyncService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Imports one PlatformConnection's orders in the background and records the
 * outcome on its own OrderSyncResult row — mirrors ProductSyncJob exactly.
 * The HTTP endpoint that dispatches this (ConnectionProfileController::
 * syncOrders()/queueOrderSync()) returns immediately, before this ever runs;
 * this is the fix for "Sync orders now" hitting PHP's max_execution_time.
 *
 * `sinceIso` captures the resolved cursor decision AT DISPATCH TIME (not
 * re-derived here) — the job is a pure executor of what the controller
 * already decided (incremental from the connection's own cursor, a resolved
 * default range for a genuine first sync, or null/no lower bound at all for
 * an explicit full resync).
 */
class OrderSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly string $resultId,
        public readonly ?string $sinceIso = null,
    ) {}

    public function handle(OrderSyncService $syncService): void
    {
        $result = OrderSyncResult::withoutTenancy(
            fn () => OrderSyncResult::query()->find($this->resultId),
        );

        if ($result === null) {
            return;
        }

        $result->update(['status' => OrderSyncResult::STATUS_RUNNING, 'started_at' => now()]);

        $store = Store::query()->find($result->store_id);
        $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($result->platform_connection_id));

        if ($store === null || $connection === null) {
            $this->finish($result, [
                'status' => OrderSyncResult::STATUS_FAILED,
                'last_error' => 'Store or connection no longer exists.',
            ]);

            return;
        }

        try {
            $since = $this->sinceIso !== null ? Carbon::parse($this->sinceIso) : null;
            $log = $syncService->syncFromPlatform($store, $connection, $since);

            $summary = $log->summary ?? [];
            $imported = (int) ($summary['created'] ?? 0);
            $updated = (int) ($summary['updated'] ?? 0);
            $skipped = (int) ($summary['skipped'] ?? 0);
            $failed = (int) ($summary['failed'] ?? 0);

            // The job — not the controller, which already returned — is the
            // only place that can safely stamp "sync finished" bookkeeping,
            // since in real (non-`sync`) queue usage the controller's
            // response goes out long before this ever runs.
            PlatformConnection::withoutTenancy(fn () => $connection->update([
                'metadata' => array_merge($connection->metadata ?? [], [
                    'order_sync' => [
                        'last_synced_at' => now()->toIso8601String(),
                        'last_error' => $log->status === 'failed' ? $log->error_message : null,
                    ],
                ]),
            ]));

            $this->finish($result, [
                'status' => $log->status === 'failed' && $imported === 0 && $updated === 0
                    ? OrderSyncResult::STATUS_FAILED
                    : OrderSyncResult::STATUS_COMPLETED,
                'imported_count' => $imported,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'failed_count' => $failed,
                'last_error' => $log->status === 'failed' ? $log->error_message : null,
            ]);
        } catch (Throwable $e) {
            PlatformConnection::withoutTenancy(fn () => $connection->update([
                'metadata' => array_merge($connection->metadata ?? [], [
                    'order_sync' => ['last_synced_at' => $connection->metadata['order_sync']['last_synced_at'] ?? null, 'last_error' => $e->getMessage()],
                ]),
            ]));

            $this->finish($result, [
                'status' => OrderSyncResult::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array{status: string, last_error: ?string, imported_count?: int, updated_count?: int, skipped_count?: int, failed_count?: int} $outcome */
    private function finish(OrderSyncResult $result, array $outcome): void
    {
        $result->update([
            'status' => $outcome['status'],
            'imported_count' => $outcome['imported_count'] ?? 0,
            'updated_count' => $outcome['updated_count'] ?? 0,
            'skipped_count' => $outcome['skipped_count'] ?? 0,
            'failed_count' => $outcome['failed_count'] ?? 0,
            'last_error' => $outcome['last_error'],
            'completed_at' => now(),
        ]);

        $result->batch?->refreshCounts();
    }
}
