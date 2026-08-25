<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Jobs\ExternalStockPushJob;
use App\Jobs\RecheckWaitingStockOrdersJob;
use App\Models\InventoryAdjustment;
use App\Models\InventoryItem;
use App\Models\InventoryLedgerEntry;
use App\Models\ProductInventoryLink;
use App\Models\Stock;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Models\Warehouse;
use App\Models\WarehouseInventoryBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryEngine
{
    public function setOnHand(InventoryItem $item, Warehouse $warehouse, int $quantity, string $eventType='adjustment', ?Model $source=null, ?User $actor=null, ?string $notes=null, bool $queueSync=true): WarehouseInventoryBalance
    {
        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            return ['on_hand' => $quantity, 'reserved' => $b->reserved, 'transfer_reserved' => $b->transfer_reserved];
        }, $eventType, $source, $actor, $notes, $queueSync);
    }

    public function adjustOnHand(InventoryItem $item, Warehouse $warehouse, int $delta, string $eventType='adjustment', ?Model $source=null, ?User $actor=null, ?string $notes=null): WarehouseInventoryBalance
    {
        return $this->mutate($item, $warehouse, fn (WarehouseInventoryBalance $b) => [
            'on_hand' => $b->on_hand + $delta, 'reserved' => $b->reserved, 'transfer_reserved' => $b->transfer_reserved,
        ], $eventType, $source, $actor, $notes);
    }

    public function reserve(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null, ?string $notes=null): WarehouseInventoryBalance
    {
        if ($quantity <= 0) return $this->balance($item, $warehouse);
        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            if ($b->available() < $quantity) {
                throw ValidationException::withMessages(['stock' => "Only {$b->available()} units are available in {$b->warehouse?->name}."]);
            }
            return ['on_hand'=>$b->on_hand,'reserved'=>$b->reserved+$quantity,'transfer_reserved'=>$b->transfer_reserved];
        }, 'reservation', $source, $actor, $notes);
    }

    public function release(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null, ?string $notes=null): WarehouseInventoryBalance
    {
        if ($quantity <= 0) return $this->balance($item, $warehouse);

        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            if ($b->reserved < $quantity) {
                throw ValidationException::withMessages(['stock'=>'Reservation is no longer available to release.']);
            }

            return [
                'on_hand'=>$b->on_hand,
                'reserved'=>$b->reserved-$quantity,
                'transfer_reserved'=>$b->transfer_reserved,
            ];
        }, 'reservation_release', $source, $actor, $notes);
    }

    public function consumeReserved(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null, ?string $notes=null): WarehouseInventoryBalance
    {
        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            if ($b->reserved < $quantity || $b->on_hand < $quantity) {
                throw ValidationException::withMessages(['stock' => 'Reserved inventory is no longer available to consume.']);
            }
            return ['on_hand'=>$b->on_hand-$quantity,'reserved'=>$b->reserved-$quantity,'transfer_reserved'=>$b->transfer_reserved];
        }, 'sale', $source, $actor, $notes);
    }

    public function reserveForTransfer(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null): WarehouseInventoryBalance
    {
        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            if ($b->available() < $quantity) throw ValidationException::withMessages(['stock'=>'Insufficient stock for transfer.']);
            return ['on_hand'=>$b->on_hand,'reserved'=>$b->reserved,'transfer_reserved'=>$b->transfer_reserved+$quantity];
        }, 'transfer_reserve', $source, $actor);
    }

    public function releaseTransferReservation(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null): WarehouseInventoryBalance
    {
        if ($quantity <= 0) return $this->balance($item, $warehouse);

        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            if ($b->transfer_reserved < $quantity) {
                throw ValidationException::withMessages(['stock'=>'Transfer reservation is no longer available to release.']);
            }

            return [
                'on_hand'=>$b->on_hand,
                'reserved'=>$b->reserved,
                'transfer_reserved'=>$b->transfer_reserved-$quantity,
            ];
        }, 'transfer_release', $source, $actor);
    }

    public function shipTransfer(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null): WarehouseInventoryBalance
    {
        return $this->mutate($item, $warehouse, function (WarehouseInventoryBalance $b) use ($quantity): array {
            if ($b->transfer_reserved < $quantity || $b->on_hand < $quantity) throw ValidationException::withMessages(['stock'=>'Transfer reservation is no longer available.']);
            return ['on_hand'=>$b->on_hand-$quantity,'reserved'=>$b->reserved,'transfer_reserved'=>$b->transfer_reserved-$quantity];
        }, 'transfer_out', $source, $actor);
    }

    public function receiveTransfer(InventoryItem $item, Warehouse $warehouse, int $quantity, ?Model $source=null, ?User $actor=null): WarehouseInventoryBalance
    {
        return $this->adjustOnHand($item, $warehouse, $quantity, 'transfer_in', $source, $actor);
    }

    public function balance(InventoryItem $item, Warehouse $warehouse): WarehouseInventoryBalance
    {
        $this->assertWarehouseAccess($item, $warehouse);
        return WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::firstOrCreate(
            ['inventory_item_id'=>$item->id,'warehouse_id'=>$warehouse->id],
            ['organization_id'=>$item->organization_id,'on_hand'=>0,'reserved'=>0,'transfer_reserved'=>0],
        ));
    }

    private function mutate(InventoryItem $item, Warehouse $warehouse, callable $next, string $eventType, ?Model $source, ?User $actor, ?string $notes=null, bool $queueSync=true): WarehouseInventoryBalance
    {
        $this->assertWarehouseAccess($item, $warehouse);

        return DB::transaction(function () use ($item,$warehouse,$next,$eventType,$source,$actor,$notes,$queueSync): WarehouseInventoryBalance {
            $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::firstOrCreate(
                ['inventory_item_id'=>$item->id,'warehouse_id'=>$warehouse->id],
                ['organization_id'=>$item->organization_id,'on_hand'=>0,'reserved'=>0,'transfer_reserved'=>0],
            ));
            $balance = WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail());
            $before = ['on_hand'=>(int)$balance->on_hand,'reserved'=>(int)$balance->reserved,'transfer_reserved'=>(int)$balance->transfer_reserved];
            $after = $next($balance);
            if ((int)$after['on_hand'] < 0 || (int)$after['reserved'] < 0 || (int)$after['transfer_reserved'] < 0) {
                throw ValidationException::withMessages(['stock'=>'Inventory balances cannot become negative.']);
            }
            $beforeAvailable = max(0,$before['on_hand']-$before['reserved']-$before['transfer_reserved']);
            $balance->update($after);
            $afterAvailable = $balance->available();

            InventoryLedgerEntry::withoutOrganizationTenancy(fn () => InventoryLedgerEntry::create([
                'organization_id'=>$item->organization_id,'inventory_item_id'=>$item->id,'warehouse_id'=>$warehouse->id,
                'event_type'=>$eventType,'on_hand_delta'=>$balance->on_hand-$before['on_hand'],
                'reserved_delta'=>$balance->reserved-$before['reserved'],'transfer_reserved_delta'=>$balance->transfer_reserved-$before['transfer_reserved'],
                'on_hand_after'=>$balance->on_hand,'reserved_after'=>$balance->reserved,'transfer_reserved_after'=>$balance->transfer_reserved,
                'source_type'=>$source?->getMorphClass(),'source_id'=>$source?->getKey(),'reference'=>$source?->getKey(),'notes'=>$notes,'actor_user_id'=>$actor?->id,
            ]));

            $this->mirrorLegacy($item,$warehouse,$balance);
            if ($queueSync && $beforeAvailable !== $afterAvailable && $warehouse->isSellable()) {
                $this->queueSync($item,$beforeAvailable,$afterAvailable,$source,$eventType);
            }
            // Waiting Stock Reallocation: available stock going UP at a
            // sellable warehouse (a manual adjustment, a received transfer, a
            // resellable return restock, a released reservation, …) may be
            // exactly what an order stuck in WaitingForStock has been
            // missing — recheck it instead of leaving it blocked until
            // someone happens to look at a transfer that may not even exist.
            // Never fires on available going DOWN (reserve/consume/ship), so
            // this never competes with the mutation that just ran.
            if ($afterAvailable > $beforeAvailable && $warehouse->isSellable()) {
                RecheckWaitingStockOrdersJob::dispatch($item->id, $warehouse->id)->afterCommit();
            }
            return $balance->fresh();
        });
    }

    private function assertWarehouseAccess(InventoryItem $item, Warehouse $warehouse): void
    {
        $allowed = $warehouse->owner_organization_id === $item->organization_id
            || $warehouse->operator_organization_id === $item->organization_id
            || $warehouse->accessibleOrganizations()->where('organizations.id',$item->organization_id)->wherePivot('is_active',true)->exists();
        if (! $allowed) throw ValidationException::withMessages(['warehouse'=>'Warehouse is not assigned to this organization.']);
    }

    private function mirrorLegacy(InventoryItem $item, Warehouse $warehouse, WarehouseInventoryBalance $balance): void
    {
        $available = $balance->available();
        ProductInventoryLink::withoutOrganizationTenancy(function () use ($item,$warehouse,$available): void {
            ProductInventoryLink::query()->where('inventory_item_id',$item->id)->get()->each(function ($link) use ($warehouse,$available): void {
                Stock::withoutTenancy(fn () => Stock::withoutEvents(fn () => Stock::updateOrCreate(
                    ['product_id'=>$link->product_id,'variant_id'=>null,'warehouse_id'=>$warehouse->id],
                    ['quantity'=>$available,'reserved'=>0],
                )));
            });
        });
        VariantInventoryLink::withoutOrganizationTenancy(function () use ($item,$warehouse,$available): void {
            VariantInventoryLink::query()->where('inventory_item_id',$item->id)->with('variant:id,product_id')->get()->each(function ($link) use ($warehouse,$available): void {
                if ($link->variant === null) return;
                Stock::withoutTenancy(fn () => Stock::withoutEvents(fn () => Stock::updateOrCreate(
                    ['product_id'=>$link->variant->product_id,'variant_id'=>$link->product_variant_id,'warehouse_id'=>$warehouse->id],
                    ['quantity'=>$available,'reserved'=>0],
                )));
            });
        });
    }

    private function queueSync(InventoryItem $item, int $before, int $after, ?Model $source, string $eventType): void
    {
        // (productId, variantId) pairs — never flattened into a bare product id
        // list, so a variant-level movement pushes THAT variant's Shopify
        // inventory_item_id/WooCommerce variation, not the parent product's.
        $targets = [];

        $directIds = ProductInventoryLink::withoutOrganizationTenancy(
            fn () => ProductInventoryLink::query()->where('inventory_item_id', $item->id)->pluck('product_id')->all()
        );
        foreach ($directIds as $productId) {
            $targets[] = [$productId, null];
        }

        $variantLinks = VariantInventoryLink::withoutOrganizationTenancy(
            fn () => VariantInventoryLink::query()
                ->where('inventory_item_id', $item->id)
                ->with('variant:id,product_id')
                ->get()
        );
        foreach ($variantLinks as $link) {
            if ($link->variant === null) {
                continue;
            }
            $targets[] = [$link->variant->product_id, $link->product_variant_id];
        }

        foreach ($targets as [$productId, $variantId]) {
            $product = \App\Models\Product::withoutTenancy(
                fn () => \App\Models\Product::query()->with('store')->find($productId)
            );
            if ($product?->store === null) {
                continue;
            }

            $adjustment = InventoryAdjustment::withoutTenancy(fn () => InventoryAdjustment::create([
                'store_id' => $product->store_id,
                'product_id' => $product->id,
                'source' => 'inventory_engine',
                'adjustable_type' => $source?->getMorphClass(),
                'adjustable_id' => $source?->getKey(),
                'quantity_change' => $after - $before,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'sync_status' => 'pending',
                'notes' => $eventType,
            ]));

            ExternalStockPushJob::dispatch($productId, $variantId, null, $adjustment->id)->afterCommit();
        }
    }
}
