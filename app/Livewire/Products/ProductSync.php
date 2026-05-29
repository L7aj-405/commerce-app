<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use App\Services\Sync\ProductSyncService;
use Carbon\Carbon;
use Livewire\Component;

class ProductSync extends Component
{
    public Store $store;

    public bool $showModal = false;

    // Sync state machine
    public bool $syncInProgress = false;
    public bool $syncDone       = false;
    public int  $syncProgress   = 0;
    public string $syncStatus   = '';
    public array $syncQueue     = [];   // remaining connection IDs to process
    public array $syncResults   = [];   // keyed by platform
    public float $startedAt     = 0.0;
    public float $elapsedSeconds = 0.0;

    // User options
    public array $selectedConnectionIds = [];
    public bool $doSyncProducts = true;
    public bool $doSyncVariants = true;
    public bool $doSyncStock    = false;

    public function openModal(): void
    {
        $this->reset([
            'syncResults', 'syncQueue', 'syncInProgress', 'syncDone',
            'syncProgress', 'syncStatus', 'elapsedSeconds', 'startedAt',
        ]);

        $this->selectedConnectionIds = $this->store->connections()
            ->where('status', 'active')
            ->pluck('id')
            ->toArray();

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        if ($this->syncDone) {
            $this->dispatch('sync-completed');
        }

        $this->showModal = false;
    }

    public function startSync(): void
    {
        if (empty($this->selectedConnectionIds)) {
            $this->dispatch('notify', message: 'Select at least one platform to sync.', type: 'error');
            return;
        }

        $this->syncQueue      = array_values($this->selectedConnectionIds);
        $this->syncResults    = [];
        $this->syncInProgress = true;
        $this->syncDone       = false;
        $this->syncProgress   = 0;
        $this->syncStatus     = 'Starting sync…';
        $this->startedAt      = (float) microtime(true);

        // Kick off the processing loop via browser event → Alpine listener
        $this->dispatch('sync-tick');
    }

    /**
     * Process one platform from the queue. Called repeatedly via sync-tick events.
     * Each call syncs a single platform's products then dispatches the next tick.
     */
    public function processNext(): void
    {
        if (!$this->syncInProgress) {
            return;
        }

        if (empty($this->syncQueue)) {
            $this->finishSync();
            return;
        }

        $connectionId = array_shift($this->syncQueue);
        $connection   = PlatformConnection::find($connectionId);

        if ($connection === null) {
            // Unknown connection — skip and continue
            if (!empty($this->syncQueue)) {
                $this->dispatch('sync-tick');
            } else {
                $this->finishSync();
            }
            return;
        }

        $label = $connection->label ?: ucfirst($connection->platform);
        $this->syncStatus = "Syncing from {$label}…";

        // Progress: spread 5%→90% across all platforms
        $total            = count($this->selectedConnectionIds);
        $processed        = $total - count($this->syncQueue);
        $this->syncProgress = (int) max(5, min(90, ($processed / $total) * 90));

        try {
            $syncService = app(ProductSyncService::class);
            $result      = $syncService->syncFromPlatform($this->store, $connection->platform);

            $this->syncResults[$connection->platform] = [
                'label'   => $label,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'failed'  => $result['failed']  ?? 0,
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $this->syncResults[$connection->platform] = [
                'label'   => $label,
                'created' => 0,
                'updated' => 0,
                'failed'  => 0,
                'error'   => $e->getMessage(),
            ];
        }

        if (!empty($this->syncQueue)) {
            $this->dispatch('sync-tick');
        } else {
            $this->finishSync();
        }
    }

    private function finishSync(): void
    {
        $this->syncInProgress = false;
        $this->syncDone       = true;
        $this->syncProgress   = 100;
        $this->syncStatus     = 'Sync complete!';
        $this->elapsedSeconds = round((float) microtime(true) - $this->startedAt, 1);

        $this->dispatch('sync-completed');
    }

    public function getElapsedTime(): string
    {
        $seconds = (int) $this->elapsedSeconds;

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;

        return "{$mins}:" . str_pad((string) $secs, 2, '0', STR_PAD_LEFT);
    }

    public function getSyncSummary(): array
    {
        $created = 0;
        $updated = 0;
        $failed  = 0;

        foreach ($this->syncResults as $r) {
            $created += $r['created'] ?? 0;
            $updated += $r['updated'] ?? 0;
            $failed  += $r['failed']  ?? 0;
        }

        return compact('created', 'updated', 'failed');
    }

    public function render()
    {
        $connections = $this->store->connections()
            ->where('status', 'active')
            ->get()
            ->map(function (PlatformConnection $conn): array {
                $lastSync = SyncLog::where('platform_connection_id', $conn->id)
                    ->where('direction', 'pull')
                    ->whereNotNull('completed_at')
                    ->latest('completed_at')
                    ->first()
                    ?->completed_at;

                return [
                    'id'            => $conn->id,
                    'platform'      => $conn->platform,
                    'label'         => $conn->label ?: ucfirst($conn->platform),
                    'product_count' => $this->store->products()
                        ->where('platform', $conn->platform)
                        ->count(),
                    'last_sync'     => $lastSync instanceof Carbon ? $lastSync->diffForHumans() : null,
                ];
            });

        return view('livewire.products.product-sync-modal', [
            'connections' => $connections,
        ]);
    }
}
