<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\InventoryReservation;
use App\Models\InventoryTransfer;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "what should a waiting-stock order's badge say"
 * — computed from the order's InventoryReservation rows (the line-level
 * shortage records) and whatever InventoryTransfer they point to, never
 * inferred from FulfillmentStatus::WaitingForStock alone. This is the fix
 * for the reported bug: Pick & Pack/Confirmation previously showed "Waiting
 * for transfer" for EVERY waiting_for_stock order, even a single-warehouse
 * store with no transfer of any kind.
 *
 * Rules (task spec):
 *   - open shortage, no transfer, no restock flag -> "Waiting for stock"
 *   - open shortage, restock_requested_at set      -> "Restock requested"
 *   - transfer requested/approved (not shipped)    -> "Transfer requested"
 *   - transfer in_transit                          -> "Waiting for transfer"
 *   - transfer received but line still short       -> "Ready to recheck"
 *   - no open shortage left                        -> "Reserved" (about to
 *     leave Waiting for Stock; callers should already be flipping the order
 *     to ready_for_picking via AllocationCompletionService by this point)
 */
final class WaitingStockState
{
    public const AWAITING_RESTOCK = 'awaiting_restock';
    public const RESTOCK_REQUESTED = 'restock_requested';
    public const TRANSFER_REQUESTED = 'transfer_requested';
    public const WAITING_FOR_TRANSFER = 'waiting_for_transfer';
    public const READY_TO_RECHECK = 'ready_to_recheck';
    public const RESOLVED = 'resolved';

    private const LABELS = [
        self::AWAITING_RESTOCK => 'Waiting for stock',
        self::RESTOCK_REQUESTED => 'Restock requested',
        self::TRANSFER_REQUESTED => 'Transfer requested',
        self::WAITING_FOR_TRANSFER => 'Waiting for transfer',
        self::READY_TO_RECHECK => 'Ready to recheck',
        self::RESOLVED => 'Reserved',
    ];

    /**
     * @param  Collection<int, InventoryReservation>  $reservations  Must have `transfer` loaded to resolve transfer-backed states; a lazy-load is triggered otherwise.
     * @return array{state: string, label: string}
     */
    public static function forReservations(Collection $reservations): array
    {
        $open = $reservations->filter(fn (InventoryReservation $r) => (int) $r->shortage_quantity > 0);

        if ($open->isEmpty()) {
            return self::result(self::RESOLVED);
        }

        /** @var InventoryTransfer|null $transfer */
        $transfer = $open->map(fn (InventoryReservation $r) => $r->transfer)
            ->filter()
            ->filter(fn (InventoryTransfer $t) => $t->status !== InventoryTransfer::CANCELLED)
            ->sortByDesc(fn (InventoryTransfer $t) => $t->created_at)
            ->first();

        if ($transfer !== null) {
            return match ($transfer->status) {
                InventoryTransfer::IN_TRANSIT => self::result(self::WAITING_FOR_TRANSFER),
                InventoryTransfer::RECEIVED => self::result(self::READY_TO_RECHECK),
                default => self::result(self::TRANSFER_REQUESTED), // requested|approved
            };
        }

        if ($open->contains(fn (InventoryReservation $r) => $r->restock_requested_at !== null)) {
            return self::result(self::RESTOCK_REQUESTED);
        }

        return self::result(self::AWAITING_RESTOCK);
    }

    /** @return array{state: string, label: string} */
    private static function result(string $state): array
    {
        return ['state' => $state, 'label' => self::LABELS[$state]];
    }
}
