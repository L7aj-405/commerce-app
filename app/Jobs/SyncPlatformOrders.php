<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformConnection;
use App\Services\Sync\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncPlatformOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly PlatformConnection $platformConnection,
    ) {}

    public function handle(OrderSyncService $orderSync): void
    {
        $orderSync->syncFromPlatform(
            $this->platformConnection->store,
            $this->platformConnection,
        );
    }
}
