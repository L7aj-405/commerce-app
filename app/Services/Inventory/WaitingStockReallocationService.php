<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Waiting Stock Reallocation — recovers orders stuck in WaitingForStock once
 * the target warehouse actually has stock again, without waiting for a
 * transfer that may not even exist.
 *
 * This is the direct fix for the reported bug: adding stock manually to the
 * warehouse never rechecked anything, so a waiting order stayed blocked
 * forever unless a transfer happened to be involved. `InventoryEngine`
 * dispatches `RecheckWaitingStockOrdersJob` (see there) whenever available
 * stock increases at a sellable warehouse; that job calls recheck() here.
 *
 * Never consumes stock — only reserves what was previously short, via the
 * exact same InventoryEngine::reserve() the initial WarehouseAllocationService
 * ::allocate() call uses. Consumption still only ever happens at
 * ready_for_delivery (OrderWorkflowService / WarehouseAllocationService::consume()),
 * untouched by this class.
 */
class WaitingStockReallocationService
{
    public function __construct(
        private readonly InventoryEngine $inventory,
        private readonly AllocationCompletionService $completion,
    ) {}

    /**
     * @return array{reservations_checked: int, resolved: int, partially_resolved: int}
     */
    public function recheck(InventoryItem $item, Warehouse $warehouse, ?User $actor = null): array
    {
        return DB::transaction(function () use ($item, $warehouse, $actor): array {
            // Lock every open shortage row for this (item, warehouse) up
            // front, for the DURATION of this transaction — a second,
            // concurrent recheck() for the same pair blocks here until this
            // one commits, so the two can never both reserve against the
            // same freshly-available units (the actual double-reservation
            // guard is InventoryEngine::reserve()'s own balance-row lock,
            // but locking the reservations too means a racing recheck never
            // even sees a stale shortage_quantity mid-flight).
            $reservations = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
                ->where('inventory_item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('shortage_quantity', '>', 0)
                ->with('allocation')
                ->lockForUpdate()
                ->get());

            // FIFO: oldest confirmed/allocated first, then reservation id as
            // a stable final tiebreak. InventoryAllocation.allocated_at is
            // set the moment the order was confirmed — the exact instant a
            // shortage was first detected for it — so it stands in for
            // "confirmed_at" without needing to touch the Order model.
            $ordered = $reservations->sort(function (InventoryReservation $a, InventoryReservation $b): int {
                $aTime = $a->allocation?->allocated_at ?? $a->created_at;
                $bTime = $b->allocation?->allocated_at ?? $b->created_at;

                return $aTime <=> $bTime ?: $a->id <=> $b->id;
            })->values();

            $resolved = 0;
            $partial = 0;

            foreach ($ordered as $reservation) {
                $available = $this->inventory->balance($item, $warehouse)->available();

                if ($available <= 0) {
                    // Nothing left for anyone further down the FIFO line —
                    // stop rather than iterate the rest for no reason.
                    break;
                }

                $reserveQty = min((int) $reservation->shortage_quantity, $available);

                if ($reserveQty <= 0) {
                    continue;
                }

                // reserve() re-locks the balance row itself and throws if
                // available somehow dropped below $reserveQty between the
                // read above and here — never oversells even under a race.
                $this->inventory->reserve(
                    $item,
                    $warehouse,
                    $reserveQty,
                    $reservation->allocation,
                    $actor,
                    'Reallocated after stock replenishment',
                );

                $newShortage = (int) $reservation->shortage_quantity - $reserveQty;

                $reservation->update([
                    'reserved_quantity' => $reservation->reserved_quantity + $reserveQty,
                    'shortage_quantity' => $newShortage,
                    'status' => $newShortage === 0 ? InventoryReservation::STATUS_ACTIVE : $reservation->status,
                    'resolved_at' => $newShortage === 0 ? now() : null,
                ]);

                $newShortage === 0 ? $resolved++ : $partial++;

                if ($reservation->allocation !== null) {
                    $this->completion->syncIfComplete($reservation->allocation);
                }
            }

            return [
                'reservations_checked' => $ordered->count(),
                'resolved' => $resolved,
                'partially_resolved' => $partial,
            ];
        });
    }
}
