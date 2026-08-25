<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\FulfillmentStatus;
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

        $candidateResult=$this->candidateWarehouses($organization,$city,$order);
        $candidates=$candidateResult['warehouses'];
        if ($candidates->isEmpty()) throw ValidationException::withMessages(['warehouse'=>'No active warehouse can serve this order.']);

        $scored=$candidates->map(fn (Warehouse $w) => ['warehouse'=>$w,'ratio'=>$this->fillRatio($w,$requirements)]);
        $full=$scored->filter(fn ($x)=>$x['ratio']>=1)->sortBy(fn($x)=>$x['warehouse']->service_priority ?? 9999)->first();
        $chosen=$full ?? $scored->sortByDesc('ratio')->first();
        $threshold=(float)data_get($organization->settings,'inventory.transfer_threshold',0.75);
        $strategy=$full?'single_warehouse':(($chosen['ratio']??0)>=$threshold?'replenish_local':'best_available');
        // Surfaced to the Waiting for Stock UI (never silent): the order had a
        // city, but no warehouse is configured to serve it, so the store's
        // default/primary warehouse was used as a fallback instead.
        $notes=($city!==null && ! $candidateResult['city_mapped'])
            ? "No warehouse configured to serve \"{$city->name}\"; used the store's default warehouse."
            : null;

        return DB::transaction(function () use ($order,$organization,$city,$actor,$requirements,$chosen,$strategy,$notes): InventoryAllocation {
            /** @var Warehouse $warehouse */ $warehouse=$chosen['warehouse'];
            $allocation=InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::create([
                'organization_id'=>$organization->id,'store_id'=>$order->store_id,'source_type'=>$order->getMorphClass(),'source_id'=>$order->id,
                'city_id'=>$city?->id,'warehouse_id'=>$warehouse->id,'status'=>InventoryAllocation::STATUS_RESERVED,'strategy'=>$strategy,'fill_ratio'=>$chosen['ratio'],'allocated_at'=>now(),'notes'=>$notes,
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

    /**
     * Manually request a transfer for one open shortage line — the action
     * behind Waiting for Stock's "Request transfer" button, for a shortage
     * that didn't get one automatically at confirm time (no source warehouse
     * had enough stock then, but one does now) or that the agent wants to
     * source from a specific warehouse rather than whichever allocate()
     * picked. Never creates a transfer when no other warehouse actually has
     * the stock — throws instead, so the UI never shows a fake/impossible
     * transfer.
     *
     * @throws ValidationException when the reservation is already resolved/has
     *   a live transfer, or no other warehouse has enough available stock
     */
    public function requestTransferForShortage(InventoryReservation $reservation, ?User $actor = null, ?Warehouse $preferredSource = null): \App\Models\InventoryTransfer
    {
        if ((int) $reservation->shortage_quantity <= 0) {
            throw ValidationException::withMessages(['reservation' => 'This line has no open shortage.']);
        }

        $reservation->loadMissing(['allocation', 'inventoryItem', 'warehouse', 'transfer']);

        if ($reservation->transfer !== null && in_array($reservation->transfer->status, [\App\Models\InventoryTransfer::REQUESTED, \App\Models\InventoryTransfer::APPROVED, \App\Models\InventoryTransfer::IN_TRANSIT], true)) {
            throw ValidationException::withMessages(['reservation' => 'A transfer is already in progress for this line.']);
        }

        $organization = Organization::query()->find($reservation->organization_id);
        abort_if($organization === null, 422, 'Reservation has no organization.');

        $qty = (int) $reservation->shortage_quantity;
        $source = $preferredSource ?? $this->findTransferSource($organization, $reservation->warehouse, $reservation->inventoryItem, $qty);

        if ($source === null) {
            throw ValidationException::withMessages(['warehouse' => 'No other warehouse has enough available stock for this item.']);
        }

        $transfer = $this->transfers->request(
            $organization,
            $source,
            $reservation->warehouse,
            [['inventory_item_id' => $reservation->inventoryItem->id, 'quantity' => $qty, 'allocation_id' => $reservation->allocation_id]],
            $actor,
            'order_shortage_manual',
        );

        $reservation->update(['inventory_transfer_id' => $transfer->id]);

        return $transfer;
    }

    /**
     * Repairs a shortage reservation whose inventory_item_id no longer
     * matches what the order line actually resolves to today — the case
     * left behind by the CatalogInventoryService::resolve() bug where a
     * variant-level order line was bound to the parent product's stale
     * product-level item. Called from the "Recheck stock" path so an
     * already-stuck order can self-heal instead of staying wrong forever.
     *
     * Deliberately conservative: only repoints a reservation that has
     * NOTHING reserved yet (reserved_quantity === 0 — nothing real to
     * unwind), and only when exactly one freshly-resolved requirement has no
     * matching reservation at all on this allocation. Any other shape
     * (ambiguous, multiple mismatches, already-reserved) is left untouched
     * rather than guessed at — never repairs onto or off a line that
     * genuinely still needs its own reservation.
     *
     * @return string|null a human-readable description of what was repaired, or null if nothing needed fixing
     */
    public function repairReservationItem(Order|PosOrder $order, InventoryReservation $reservation): ?string
    {
        if ((int) $reservation->reserved_quantity > 0) {
            return null;
        }

        $requirements = $this->requirements($order);

        if (array_key_exists($reservation->inventory_item_id, $requirements)) {
            return null;
        }

        $reservation->loadMissing('allocation.reservations');
        $existingItemIds = $reservation->allocation->reservations->pluck('inventory_item_id')->all();
        $missingItemIds  = array_values(array_diff(array_keys($requirements), $existingItemIds));

        if (count($missingItemIds) !== 1) {
            return null;
        }

        $correctItemId = $missingItemIds[0];
        $qty           = $requirements[$correctItemId];
        $oldItemId     = $reservation->inventory_item_id;

        $reservation->update([
            'inventory_item_id'  => $correctItemId,
            'requested_quantity' => $qty,
            'shortage_quantity'  => $qty,
        ]);

        return "Inventory mapping repaired — this line was pointed at the wrong stock item ({$oldItemId}) and now points at the correct one.";
    }

    /**
     * Reconciles an allocation against its order's CURRENT line resolution —
     * creates a reservation for any order line that was dropped entirely at
     * confirm time (unresolvable then, under an older/buggier mapping) but
     * resolves cleanly now, e.g. after OrderLineInventoryResolver/connector
     * fixes. `requirements()` is idempotent and re-derives from the order's
     * live line items every time, so a line already covered by an existing
     * reservation is simply skipped — this only ever ADDS a reservation for
     * an item nothing on the allocation currently accounts for, it never
     * touches an existing one (that's repairReservationItem()'s job).
     *
     * @return array<int, InventoryReservation> newly created reservations
     */
    public function reconcileAllocation(Order|PosOrder $order, InventoryAllocation $allocation): array
    {
        $requirements = $this->requirements($order);
        $existingItemIds = $allocation->reservations()->pluck('inventory_item_id')->all();
        $created = [];

        foreach ($requirements as $itemId => $qty) {
            if (in_array($itemId, $existingItemIds, true)) {
                continue;
            }

            $item = InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()->find($itemId));

            if ($item === null) {
                continue;
            }

            $created[] = DB::transaction(function () use ($allocation, $item, $qty): InventoryReservation {
                $balance = $this->inventory->balance($item, $allocation->warehouse);
                $reserve = min($qty, $balance->available());

                if ($reserve > 0) {
                    $this->inventory->reserve($item, $allocation->warehouse, $reserve, $allocation, null, 'Reconciled missing order line mapping');
                }

                $short = $qty - $reserve;

                return InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::create([
                    'organization_id' => $allocation->organization_id, 'allocation_id' => $allocation->id,
                    'inventory_item_id' => $item->id, 'warehouse_id' => $allocation->warehouse_id,
                    'requested_quantity' => $qty, 'reserved_quantity' => $reserve, 'shortage_quantity' => $short,
                    'status' => $short > 0 ? InventoryReservation::STATUS_WAITING_TRANSFER : InventoryReservation::STATUS_ACTIVE,
                    'resolved_at' => $short === 0 ? now() : null,
                ]));
            });
        }

        return $created;
    }

    /**
     * The operational fulfillment status that follows from where an
     * allocation landed — used by both the online-order confirm step and POS
     * checkout so the two never diverge: fully reserved means the warehouse
     * can start picking now, anything short means it is waiting on the
     * replenishment transfer allocate() already queued.
     */
    public function statusForAllocation(InventoryAllocation $allocation): FulfillmentStatus
    {
        return match ($allocation->status) {
            InventoryAllocation::STATUS_RESERVED => FulfillmentStatus::ReadyForPicking,
            InventoryAllocation::STATUS_WAITING_TRANSFER,
            InventoryAllocation::STATUS_INSUFFICIENT => FulfillmentStatus::WaitingForStock,
            default => FulfillmentStatus::ReadyForPicking,
        };
    }

    private function allocationFor(Order|PosOrder $order): ?InventoryAllocation
    {
        return InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()->where('source_type',$order->getMorphClass())->where('source_id',$order->id)->first());
    }

    /**
     * @return array<string,int> inventory item id => units
     *
     * Online lines already carry a resolved `inventory_item_id` from
     * OrderLineItems::for() (via OrderLineInventoryResolver — the single
     * source of truth for external-id/SKU mapping, so this never re-derives
     * it differently). POS lines don't carry one (their ids are already
     * local — see OrderLineItems::fromOnline() vs fromPos()), so those are
     * resolved here directly via CatalogInventoryService::resolve(), same as
     * before. Either way, an unresolved line is simply excluded — never
     * silently treated as satisfied.
     */
    private function requirements(Order|PosOrder $order): array
    {
        $out = [];

        foreach (OrderLineItems::for($order) as $line) {
            $itemId = $line['inventory_item_id']
                ?? $this->catalog->resolve($line['product_id'] ?? null, $line['variant_id'] ?? null)?->id;

            if ($itemId === null) {
                continue;
            }

            $out[$itemId] = ($out[$itemId] ?? 0) + (int) $line['quantity'];
        }

        return $out;
    }

    /** @return array{warehouses: Collection, city_mapped: bool} */
    private function candidateWarehouses(Organization $organization, ?City $city, Order|PosOrder $order): array
    {
        $warehouses=Warehouse::withoutTenancy(fn () => Warehouse::query()->sellable()->where('is_active',true)
            ->where(function($q) use($organization){$q->where('owner_organization_id',$organization->id)->orWhere('operator_organization_id',$organization->id)->orWhereHas('accessibleOrganizations',fn($q)=>$q->where('organizations.id',$organization->id)->where('warehouse_organization_access.is_active',true));})
            ->get());
        if ($city!==null) {
            $serving=$warehouses->filter(fn(Warehouse $w)=>$w->serviceCities()->where('cities.id',$city->id)->wherePivot('is_active',true)->exists())
                ->map(function(Warehouse $w) use($city){$w->service_priority=(int)$w->serviceCities()->where('cities.id',$city->id)->value('warehouse_service_areas.priority'); return $w;});
            if ($serving->isNotEmpty()) return ['warehouses'=>$serving->values(),'city_mapped'=>true];
        }
        $primary=$order->store?->getPrimaryWarehouse();
        return ['warehouses'=>$warehouses->sortByDesc(fn(Warehouse $w)=>$primary?->id===$w->id)->values(),'city_mapped'=>false];
    }

    private function fillRatio(Warehouse $warehouse, array $requirements): float
    {
        $required=0; $fillable=0;
        foreach($requirements as $itemId=>$qty){$required+=$qty; $item=InventoryItem::withoutOrganizationTenancy(fn()=>InventoryItem::query()->findOrFail($itemId)); $fillable+=min($qty,$this->inventory->balance($item,$warehouse)->available());}
        return $required>0?$fillable/$required:0.0;
    }

    /**
     * The first other sellable warehouse in this organization holding enough
     * available stock to cover $qty of $item — null when none does. Public
     * so the Waiting for Stock "Request transfer" action can suggest/create
     * a transfer for a shortage that didn't get one automatically (e.g. no
     * source had enough stock at confirm time, but one does now).
     */
    public function findTransferSource(Organization $org, Warehouse $destination, InventoryItem $item, int $qty): ?Warehouse
    {
        return Warehouse::withoutTenancy(fn () => Warehouse::query()->sellable()->where('is_active',true)->where('id', '!=', $destination->id)
            ->where(function($q) use($org){$q->where('owner_organization_id',$org->id)->orWhere('operator_organization_id',$org->id)->orWhereHas('accessibleOrganizations',fn($q)=>$q->where('organizations.id',$org->id)->where('warehouse_organization_access.is_active',true));})
            ->get()->first(fn(Warehouse $w)=>$this->inventory->balance($item,$w)->available()>=$qty));
    }
}
