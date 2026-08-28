<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\FulfillmentStatus;
use App\Models\InventoryAllocation;

/**
 * The single place that decides "this allocation's shortages are all
 * cleared — unblock the order." Extracted out of
 * InventoryTransferService::topUpAllocation() (formerly a private
 * markSourceReadyForPicking() method there) so WaitingStockReallocationService
 * can share the exact same logic when stock is replenished WITHOUT a
 * transfer (e.g. a manual stock adjustment or a resellable return) — a
 * second copy of this check would have been very easy to let drift.
 *
 * Deliberately its own class rather than a method on WarehouseAllocationService:
 * WarehouseAllocationService already depends on InventoryTransferService, so
 * InventoryTransferService depending back on WarehouseAllocationService would
 * be a circular constructor dependency. This class depends on neither.
 */
class AllocationCompletionService
{
    /**
     * If every reservation under this allocation has shortage_quantity = 0,
     * mark the allocation reserved and — only if the order is still sitting
     * in WaitingForStock — flip it to ReadyForPicking. Idempotent: calling
     * this on an already-complete/already-flipped allocation is a no-op.
     */
    public function syncIfComplete(InventoryAllocation $allocation): void
    {
        if ($allocation->reservations()->where('shortage_quantity', '>', 0)->exists()) {
            return;
        }

        if ($allocation->status !== InventoryAllocation::STATUS_RESERVED) {
            $allocation->update(['status' => InventoryAllocation::STATUS_RESERVED]);
        }

        $source = $allocation->source;

        if ($source === null) {
            return;
        }

        $current = $source->fulfillment_status ?? null;

        if ($current === FulfillmentStatus::WaitingForStock) {
            $source->forceFill([
                'fulfillment_status' => FulfillmentStatus::ReadyForPicking,
                'fulfillment_updated_at' => now(),
            ])->save();
        }
    }
}
