<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\City;
use App\Models\InventoryAllocation;
use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OrderLineItems;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseAllocationService
{
    public function __construct(private readonly CatalogInventoryService $catalog, private readonly InventoryEngine $inventory, private readonly InventoryTransferService $transfers) {}

    public function allocate(Order|PosOrder $order, ?City $city=null, ?User $actor=null): InventoryAllocation
    {
        $order->loadMissing('store.organization');
        $organization=$order->store?->organization;
        if ($organization===null) throw ValidationException::withMessages(['organization'=>'Order store has no organization.']);

        $existing=InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->where('source_type',$order->getMorphClass())->where('source_id',$order->id)->with('reservations')->first());
        if ($existing!==null) return $existing;

        $requirements=$this->requirements($order);
        if ($requirements===[]) throw ValidationException::withMessages(['items'=>'Order has no inventory-linked items.']);

        $candidates=$this->candidateWarehouses($organization,$city,$order);
        if ($candidates->isEmpty()) throw ValidationException::withMessages(['warehouse'=>'No active warehouse can serve this order.']);

        $scored=$candidates->map(fn (Warehouse $w) => ['warehouse'=>$w,'ratio'=>$this->fillRatio($w,$requirements)]);
        $full=$scored->filter(fn ($x)=>$x['ratio']>=1)->sortBy(fn($x)=>$x['warehouse']->service_priority ?? 9999)->first();
        $chosen=$full ?? $scored->sortByDesc('ratio')->first();
        $threshold=(float)data_get($organization->settings,'inventory.transfer_threshold',0.75);
        $strategy=$full?'single_warehouse':(($chosen['ratio']??0)>=$threshold?'replenish_local':'best_available');

        return DB::transaction(function () use ($order,$organization,$city,$actor,$requirements,$chosen,$strategy): InventoryAllocation {
            /** @var Warehouse $warehouse */ $warehouse=$chosen['warehouse'];
            $allocation=InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::create([
                'organization_id'=>$organization->id,'store_id'=>$order->store_id,'source_type'=>$order->getMorphClass(),'source_id'=>$order->id,
                'city_id'=>$city?->id,'warehouse_id'=>$warehouse->id,'status'=>InventoryAllocation::STATUS_RESERVED,'strategy'=>$strategy,'fill_ratio'=>$chosen['ratio'],'allocated_at'=>now(),
            ]));
            $shortages=[];
            foreach ($requirements as $itemId=>$qty) {
                $item=InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()->findOrFail($itemId));
                $balance=$this->inventory->balance($item,$warehouse);
                $reserve=min($qty,$balance->available()); $short=$qty-$reserve;
                if ($reserve>0) $this->inventory->reserve($item,$warehouse,$reserve,$allocation,$actor,'Order reservation');
                $reservation=InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::create([
                    'organization_id'=>$organization->id,'allocation_id'=>$allocation->id,'inventory_item_id'=>$item->id,'warehouse_id'=>$warehouse->id,
                    'requested_quantity'=>$qty,'reserved_quantity'=>$reserve,'shortage_quantity'=>$short,
                    'status'=>$short>0?InventoryReservation::STATUS_WAITING_TRANSFER:InventoryReservation::STATUS_ACTIVE,
                ]));
                if ($short>0) $shortages[]=['item'=>$item,'qty'=>$short,'reservation'=>$reservation];
            }
            if ($shortages!==[]) {
                $allocation->update(['status'=>InventoryAllocation::STATUS_WAITING_TRANSFER]);
                foreach ($shortages as $missing) {
                    $source=$this->findTransferSource($organization,$warehouse,$missing['item'],$missing['qty']);
                    if ($source===null) { $missing['reservation']->update(['status'=>InventoryReservation::STATUS_INSUFFICIENT]); $allocation->update(['status'=>InventoryAllocation::STATUS_INSUFFICIENT]); continue; }
                    $transfer=$this->transfers->request($organization,$source,$warehouse,[['inventory_item_id'=>$missing['item']->id,'quantity'=>$missing['qty'],'allocation_id'=>$allocation->id]],$actor,'order_shortage');
                    $missing['reservation']->update(['inventory_transfer_id'=>$transfer->id]);
                }
            }
            return $allocation->fresh(['reservations.inventoryItem','warehouse','city']);
        });
    }

    public function release(Order|PosOrder $order, ?User $actor=null): void
    {
        $allocation=$this->allocationFor($order); if ($allocation===null || $allocation->status===InventoryAllocation::STATUS_RELEASED) return;
        DB::transaction(function () use ($allocation,$actor): void {
            $allocation->load(['reservations.inventoryItem','reservations.warehouse','reservations.transfer.items']);
            $handledTransfers = [];

            foreach ($allocation->reservations as $r) {
                if ($r->status !== InventoryReservation::STATUS_CONSUMED && $r->reserved_quantity > 0) {
                    $this->inventory->release($r->inventoryItem,$r->warehouse,$r->reserved_quantity,$allocation,$actor,'Order cancelled');
                }

                $transfer = $r->transfer;
                if ($transfer !== null && ! isset($handledTransfers[$transfer->id])) {
                    $handledTransfers[$transfer->id] = true;

                    if (in_array($transfer->status, [\App\Models\InventoryTransfer::REQUESTED, \App\Models\InventoryTransfer::APPROVED], true)) {
                        $this->transfers->cancel($transfer, $actor);
                    } elseif ($transfer->status === \App\Models\InventoryTransfer::IN_TRANSIT) {
                        // Physical stock is already moving, so do not pretend it can
                        // be put back at source. Let it arrive as free inventory,
                        // but detach it from the cancelled order first.
                        $transfer->items()
                            ->where('allocation_id', $allocation->id)
                            ->update(['allocation_id' => null]);
                    }
                }

                $r->update([
                    'status'=>InventoryReservation::STATUS_RELEASED,
                    'reserved_quantity'=>0,
                    'inventory_transfer_id'=>null,
                ]);
            }

            $allocation->update(['status'=>InventoryAllocation::STATUS_RELEASED,'released_at'=>now()]);
        });
    }

    public function consume(Order|PosOrder $order, ?User $actor=null): void
    {
        $allocation=$this->allocationFor($order); if ($allocation===null || $allocation->status===InventoryAllocation::STATUS_CONSUMED) return;
        if ($allocation->reservations()->where('shortage_quantity','>',0)->exists()) throw ValidationException::withMessages(['stock'=>'Order is still waiting for transferred stock.']);
        DB::transaction(function () use ($allocation,$actor): void {
            $allocation->load('reservations.inventoryItem');
            foreach ($allocation->reservations as $r) {
                $this->inventory->consumeReserved($r->inventoryItem,$r->warehouse,$r->reserved_quantity,$allocation,$actor,'Order left warehouse');
                $r->update(['status'=>InventoryReservation::STATUS_CONSUMED]);
            }
            $allocation->update(['status'=>InventoryAllocation::STATUS_CONSUMED,'consumed_at'=>now()]);
        });
    }

    public function restoreConsumed(Order|PosOrder $order, ?User $actor=null): void
    {
        $allocation=$this->allocationFor($order); if ($allocation===null || $allocation->status!==InventoryAllocation::STATUS_CONSUMED) return;
        DB::transaction(function () use ($allocation,$actor): void {
            $allocation->load('reservations.inventoryItem');
            foreach ($allocation->reservations as $r) {
                $this->inventory->adjustOnHand($r->inventoryItem,$r->warehouse,$r->requested_quantity,'cancelled_before_delivery',$allocation,$actor);
                $r->update(['status'=>InventoryReservation::STATUS_RELEASED,'reserved_quantity'=>0]);
            }
            $allocation->update(['status'=>InventoryAllocation::STATUS_RELEASED,'released_at'=>now()]);
        });
    }

    private function allocationFor(Order|PosOrder $order): ?InventoryAllocation
    {
        return InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->where('source_type',$order->getMorphClass())->where('source_id',$order->id)->first());
    }

    /** @return array<string,int> inventory item id => units */
    private function requirements(Order|PosOrder $order): array
    {
        $out=[];
        $order->loadMissing('store.organization');
        $organizationId = $order->store?->organization_id;

        foreach (OrderLineItems::for($order) as $line) {
            $item = $this->catalog->resolveOrderLine(
                $line['product_id'] ?? null,
                $line['variant_id'] ?? null,
                $line['sku'] ?? null,
                $organizationId,
                $order->store_id,
            );

            if ($item === null) {
                continue;
            }

            $out[$item->id] = ($out[$item->id] ?? 0) + (int) $line['quantity'];
        }
        return $out;
    }

    private function candidateWarehouses(Organization $organization, ?City $city, Order|PosOrder $order): Collection
    {
        $warehouses=Warehouse::withoutTenancy(fn () => Warehouse::query()->sellable()->where('is_active',true)
            ->where(function($q) use($organization){$q->where('owner_organization_id',$organization->id)->orWhere('operator_organization_id',$organization->id)->orWhereHas('accessibleOrganizations',fn($q)=>$q->where('organizations.id',$organization->id)->where('warehouse_organization_access.is_active',true));})
            ->get());
        if ($city!==null) {
            $serving=$warehouses->filter(fn(Warehouse $w)=>$w->serviceCities()->where('cities.id',$city->id)->wherePivot('is_active',true)->exists())
                ->map(function(Warehouse $w) use($city){$w->service_priority=(int)$w->serviceCities()->where('cities.id',$city->id)->value('warehouse_service_areas.priority'); return $w;});
            if ($serving->isNotEmpty()) return $serving->values();
        }
        $primary=$order->store?->getPrimaryWarehouse();
        return $warehouses->sortByDesc(fn(Warehouse $w)=>$primary?->id===$w->id)->values();
    }

    private function fillRatio(Warehouse $warehouse, array $requirements): float
    {
        $required=0; $fillable=0;
        foreach($requirements as $itemId=>$qty){$required+=$qty; $item=InventoryItem::withoutOrganizationTenancy(fn()=>InventoryItem::query()->findOrFail($itemId)); $fillable+=min($qty,$this->inventory->balance($item,$warehouse)->available());}
        return $required>0?$fillable/$required:0.0;
    }

    private function findTransferSource(Organization $org, Warehouse $destination, InventoryItem $item, int $qty): ?Warehouse
    {
        return Warehouse::withoutTenancy(fn () => Warehouse::query()->sellable()->where('is_active',true)->where('id', '!=', $destination->id)
            ->where(function($q) use($org){$q->where('owner_organization_id',$org->id)->orWhere('operator_organization_id',$org->id)->orWhereHas('accessibleOrganizations',fn($q)=>$q->where('organizations.id',$org->id)->where('warehouse_organization_access.is_active',true));})
            ->get()->first(fn(Warehouse $w)=>$this->inventory->balance($item,$w)->available()>=$qty));
    }
}
