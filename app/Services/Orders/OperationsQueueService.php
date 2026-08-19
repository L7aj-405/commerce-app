<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OrderPresenter;
use Illuminate\Support\Collection;

/**
 * Cross-store operational queues, scoped by warehouse OPERATOR rather than the
 * viewer's single active store.
 *
 * Each client organization owns exactly one store (see AgencyWorkspaceTest),
 * and an agency works a client by opening that store into the session — fine
 * for confirmation, but a warehouse can physically serve several client
 * stores at once, and a picker/packer needs to see all of it without flipping
 * stores. So these queues resolve the set of warehouses the viewer's
 * organization owns or operates (mirroring
 * WarehouseAllocationService::candidateWarehouses()), pull every order
 * allocated to those warehouses regardless of store, then filter rows down to
 * stores the viewer actually holds the department permission on — the same
 * store-role check every other department page relies on. That last filter is
 * the tenant boundary: never skip it when adding a new query here.
 */
class OperationsQueueService
{
    /** Warehouses the viewer's organization owns or operates. */
    public function operatorWarehouses(User $user): Collection
    {
        $organizationId = $this->operatingOrganizationId($user);

        if ($organizationId === null) {
            return collect();
        }

        return Warehouse::withoutTenancy(fn () => Warehouse::query()
            ->where('is_active', true)
            ->where(function ($q) use ($organizationId) {
                $q->where('owner_organization_id', $organizationId)
                    ->orWhere('operator_organization_id', $organizationId);
            })
            ->get());
    }

    /**
     * The organization whose warehouses this user runs: an agency they belong
     * to (any active membership — picking/packing is done by rank-and-file
     * agency staff, not just the owner/admins who configure the `/agency`
     * workspace via User::managedAgencyOrganizations()), otherwise their
     * active store's own organization.
     */
    public function operatingOrganizationId(User $user): ?string
    {
        $agency = Organization::query()
            ->where('type', Organization::TYPE_AGENCY)
            ->where('status', 'active')
            ->where(fn ($q) => $q
                ->where('owner_user_id', $user->id)
                ->orWhereHas('memberships', fn ($q) => $q->where('user_id', $user->id)->where('is_active', true)))
            ->first();

        if ($agency !== null) {
            return $agency->id;
        }

        return $user->getActiveStore()?->organization_id;
    }

    public function isAgencyContext(User $user): bool
    {
        return $user->managedAgencyOrganizations()->isNotEmpty();
    }

    /**
     * Every order in one of the given statuses, allocated to a warehouse this
     * viewer's organization runs, restricted to stores they actually hold
     * $permission (or the coarse orders.manage) on.
     *
     * @param  array<int, FulfillmentStatus>  $statuses
     * @return array<int, array<string, mixed>>
     */
    public function queue(User $user, array $statuses, string $permission = 'orders.fulfil'): array
    {
        $warehouses = $this->operatorWarehouses($user);

        if ($warehouses->isEmpty()) {
            return [];
        }

        $warehouseIds = $warehouses->pluck('id');
        $storeIds     = $this->visibleStoreIds($user, $warehouses, $permission);

        if ($storeIds->isEmpty()) {
            return [];
        }

        $statusValues = array_map(fn (FulfillmentStatus $s) => $s->value, $statuses);

        $withRelations = ['store:id,currency,organization_id', 'store.organization:id,name', 'shippingCity', 'inventoryAllocation.warehouse', 'inventoryAllocation.city', 'inventoryAllocation.reservations'];

        $pos = PosOrder::withoutTenancy(fn () => PosOrder::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('fulfillment_status', $statusValues)
            ->whereHas('inventoryAllocation', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->with([...$withRelations, 'items'])
            ->oldest()
            ->limit(200)
            ->get());

        $online = Order::withoutTenancy(fn () => Order::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('fulfillment_status', $statusValues)
            ->whereHas('inventoryAllocation', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->with($withRelations)
            ->oldest()
            ->limit(200)
            ->get());

        $assignees = User::whereIn(
            'id',
            $pos->pluck('assigned_to')->merge($online->pluck('assigned_to'))->filter()->unique(),
        )->pluck('name', 'id')->all();

        $decorate = function (array $row, Order|PosOrder $model) use ($assignees): array {
            $row['assigned_to']              = $model->assigned_to;
            $row['assigned_at']              = $model->assigned_at?->toIso8601String();
            $row['assignee_name']            = $model->assigned_to ? ($assignees[$model->assigned_to] ?? null) : null;
            $row['client_organization_id']   = $model->store?->organization_id;
            $row['client_organization_name'] = $model->store?->organization?->name;

            return $row;
        };

        $rows = $pos->map(fn (PosOrder $o) => $decorate(OrderPresenter::pos($o), $o))
            ->concat($online->map(fn (Order $o) => $decorate(OrderPresenter::online($o), $o)));

        return $rows->sortBy('created_at')->values()->all();
    }

    /**
     * Inbound transfers awaiting receipt at a warehouse this viewer runs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transfersToReceive(User $user): array
    {
        $warehouses = $this->operatorWarehouses($user);

        if ($warehouses->isEmpty()) {
            return [];
        }

        $warehouseIds = $warehouses->pluck('id');

        $transfers = InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()
            ->whereIn('destination_warehouse_id', $warehouseIds)
            ->where('status', InventoryTransfer::IN_TRANSIT)
            ->with(['items.inventoryItem:id,name,sku', 'sourceWarehouse:id,name', 'destinationWarehouse:id,name'])
            ->orderBy('shipped_at')
            ->get());

        return $transfers->map(fn (InventoryTransfer $t) => [
            'id'                    => $t->id,
            'reference'             => $t->reference,
            'reason'                => $t->reason,
            'source_warehouse'      => $t->sourceWarehouse?->name,
            'destination_warehouse' => $t->destinationWarehouse?->name,
            'shipped_at'            => $t->shipped_at?->toIso8601String(),
            'items'                 => $t->items->map(fn ($line) => [
                'name'     => $line->inventoryItem?->name,
                'sku'      => $line->inventoryItem?->sku,
                'quantity' => (int) $line->quantity,
            ])->all(),
        ])->all();
    }

    /**
     * Resolve one InventoryTransfer, scoped to warehouses this viewer's
     * organization runs. Null when it belongs to a warehouse they don't
     * operate — the controller turns that into a 404/403.
     */
    public function findReceivableTransfer(User $user, string $transferId): ?InventoryTransfer
    {
        $warehouseIds = $this->operatorWarehouses($user)->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return null;
        }

        return InventoryTransfer::withoutOrganizationTenancy(fn () => InventoryTransfer::query()
            ->whereIn('destination_warehouse_id', $warehouseIds)
            ->find($transferId));
    }

    /**
     * Stores served by these warehouses that the viewer actually holds
     * $permission (or orders.manage) on — the row-level tenant boundary.
     */
    private function visibleStoreIds(User $user, Collection $warehouses, string $permission): Collection
    {
        $storeIds = $warehouses->flatMap(fn (Warehouse $w) => $w->stores()->pluck('stores.id'))->unique();

        if ($storeIds->isEmpty()) {
            return collect();
        }

        return Store::query()
            ->whereIn('id', $storeIds)
            ->get()
            ->filter(fn (Store $store) => $user->hasStorePermission($store, $permission) || $user->hasStorePermission($store, 'orders.manage'))
            ->pluck('id');
    }
}
