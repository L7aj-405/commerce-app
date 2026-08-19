<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\FulfillmentStatus;
use App\Models\InventoryAllocation;
use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryTransferService
{
    public function __construct(private readonly InventoryEngine $inventory) {}

    /** @param array<int,array{inventory_item_id:string,quantity:int,allocation_id?:?string}> $items */
    public function request(Organization $organization, Warehouse $source, Warehouse $destination, array $items, ?User $actor=null, string $reason='replenishment'): InventoryTransfer
    {
        if ($source->id === $destination->id) throw ValidationException::withMessages(['warehouse'=>'Source and destination must differ.']);

        return DB::transaction(function () use ($organization,$source,$destination,$items,$actor,$reason): InventoryTransfer {
            $transfer=InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::create([
                'organization_id'=>$organization->id,'source_warehouse_id'=>$source->id,'destination_warehouse_id'=>$destination->id,
                'reference'=>$this->nextReference($organization),'status'=>InventoryTransfer::REQUESTED,'reason'=>$reason,'requested_by'=>$actor?->id,
            ]));
            foreach ($items as $row) {
                $qty=(int)($row['quantity']??0); if ($qty<=0) continue;
                $item=InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()->where('organization_id',$organization->id)->findOrFail($row['inventory_item_id']));
                $this->inventory->reserveForTransfer($item,$source,$qty,$transfer,$actor);
                InventoryTransferItem::create(['inventory_transfer_id'=>$transfer->id,'inventory_item_id'=>$item->id,'quantity'=>$qty,'allocation_id'=>$row['allocation_id']??null]);
            }
            return $transfer->fresh('items');
        });
    }

    public function approve(InventoryTransfer $transfer): InventoryTransfer
    {
        abort_unless($transfer->status===InventoryTransfer::REQUESTED,422,'Transfer is not awaiting approval.');
        $transfer->update(['status'=>InventoryTransfer::APPROVED,'approved_at'=>now()]); return $transfer->refresh();
    }

    public function cancel(InventoryTransfer $transfer, ?User $actor=null): InventoryTransfer
    {
        if (! in_array($transfer->status, [InventoryTransfer::REQUESTED, InventoryTransfer::APPROVED], true)) {
            throw ValidationException::withMessages(['transfer' => 'Only a requested or approved transfer can be cancelled.']);
        }

        return DB::transaction(function () use ($transfer, $actor): InventoryTransfer {
            $transfer->load(['items.inventoryItem', 'sourceWarehouse']);

            foreach ($transfer->items as $line) {
                $this->inventory->releaseTransferReservation(
                    $line->inventoryItem,
                    $transfer->sourceWarehouse,
                    (int) $line->quantity,
                    $transfer,
                    $actor,
                );
            }

            $transfer->update(['status' => InventoryTransfer::CANCELLED]);

            return $transfer->refresh();
        });
    }

    public function ship(InventoryTransfer $transfer, ?User $actor=null): InventoryTransfer
    {
        if (!in_array($transfer->status,[InventoryTransfer::REQUESTED,InventoryTransfer::APPROVED],true)) abort(422,'Transfer cannot be shipped.');
        return DB::transaction(function () use ($transfer,$actor): InventoryTransfer {
            $transfer->load(['items.inventoryItem','sourceWarehouse']);
            foreach ($transfer->items as $line) $this->inventory->shipTransfer($line->inventoryItem,$transfer->sourceWarehouse,$line->quantity,$transfer,$actor);
            $transfer->update(['status'=>InventoryTransfer::IN_TRANSIT,'shipped_at'=>now()]); return $transfer->refresh();
        });
    }

    public function receive(InventoryTransfer $transfer, ?User $actor=null): InventoryTransfer
    {
        abort_unless($transfer->status===InventoryTransfer::IN_TRANSIT,422,'Only in-transit stock can be received.');
        return DB::transaction(function () use ($transfer,$actor): InventoryTransfer {
            $transfer->load(['items.inventoryItem','destinationWarehouse']);
            foreach ($transfer->items as $line) {
                $this->inventory->receiveTransfer($line->inventoryItem,$transfer->destinationWarehouse,$line->quantity,$transfer,$actor);
                $line->update(['received_quantity'=>$line->quantity]);
                if ($line->allocation_id) $this->topUpAllocation($line,$actor);
            }
            $transfer->update(['status'=>InventoryTransfer::RECEIVED,'received_at'=>now()]); return $transfer->refresh();
        });
    }

    private function topUpAllocation(InventoryTransferItem $line, ?User $actor): void
    {
        $reservation=InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
            ->where('allocation_id',$line->allocation_id)->where('inventory_item_id',$line->inventory_item_id)->first());
        if ($reservation===null || $reservation->shortage_quantity<=0) return;
        $qty=min($line->quantity,$reservation->shortage_quantity);
        $this->inventory->reserve($line->inventoryItem,$line->transfer->destinationWarehouse,$qty,$reservation->allocation,$actor,'Reserved after transfer receipt');
        $reservation->update(['reserved_quantity'=>$reservation->reserved_quantity+$qty,'shortage_quantity'=>$reservation->shortage_quantity-$qty,'status'=>($reservation->shortage_quantity-$qty)===0?InventoryReservation::STATUS_ACTIVE:InventoryReservation::STATUS_WAITING_TRANSFER]);
        $allocation=$reservation->allocation;
        if ($allocation->reservations()->where('shortage_quantity','>',0)->doesntExist()) {
            $allocation->update(['status'=>InventoryAllocation::STATUS_RESERVED]);
            $this->markSourceReadyForPicking($allocation);
        }
    }

    private function markSourceReadyForPicking(InventoryAllocation $allocation): void
    {
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

    private function nextReference(Organization $organization): string
    {
        // Do not use a daily COUNT here: two concurrent orders can request a
        // transfer at the same moment. A short ULID suffix keeps the reference
        // human-readable while remaining collision-safe without a table lock.
        return sprintf(
            'TRF-%s-%s',
            now()->format('Ymd'),
            strtoupper(substr((string) Str::ulid(), -8)),
        );
    }
}
