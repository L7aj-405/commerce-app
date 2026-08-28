<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryItem;
use App\Models\Warehouse;
use App\Services\Inventory\WaitingStockReallocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by InventoryEngine whenever available stock increases at a
 * sellable warehouse (a manual adjustment, a received transfer, a resellable
 * return restock, a released reservation, …) — reruns
 * WaitingStockReallocationService for that one (item, warehouse) pair so any
 * order stuck in WaitingForStock gets reconsidered immediately, instead of
 * staying blocked until someone happens to look at a transfer.
 */
class RecheckWaitingStockOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(
        public readonly string $inventoryItemId,
        public readonly string $warehouseId,
    ) {}

    /** @return array{reservations_checked: int, resolved: int, partially_resolved: int} */
    public function handle(WaitingStockReallocationService $service): array
    {
        $item = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()->find($this->inventoryItemId));
        $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::query()->find($this->warehouseId));

        if ($item === null || $warehouse === null) {
            return ['reservations_checked' => 0, 'resolved' => 0, 'partially_resolved' => 0];
        }

        return $service->recheck($item, $warehouse);
    }
}
