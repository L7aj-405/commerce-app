<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Models\InventoryAllocation;
use App\Models\InventoryItem;
use App\Models\InventoryReservation;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\ProductInventoryLink;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantInventoryLink;
use App\Models\Warehouse;
use App\Models\WarehouseInventoryBalance;
use App\Services\Inventory\AllocationCompletionService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Inventory\WaitingStockReallocationService;
use App\Services\Inventory\WarehouseAllocationService;
use App\Support\OrderLineItems;
use App\Support\OrderPresenter;
use App\Support\WaitingStockState;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
    public function __construct(
        private readonly InventoryEngine $inventory,
        private readonly WaitingStockReallocationService $reallocation,
        private readonly WarehouseAllocationService $allocations,
        private readonly AllocationCompletionService $completion,
    ) {}

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

        $withRelations = ['store:id,currency,organization_id', 'store.organization:id,name', 'shippingCity', 'inventoryAllocation.warehouse', 'inventoryAllocation.city', 'inventoryAllocation.reservations.transfer'];

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
     * Waiting for Stock, enriched with per-line shortage detail (needed /
     * available in target / missing / available elsewhere, plus the
     * transfer-aware state label and which actions apply) — what the
     * Waiting for Stock page actually renders. `queue()` alone only carries
     * the coarse allocation summary every other queue page needs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function waitingStockDetails(User $user): array
    {
        $rows = $this->queue($user, [FulfillmentStatus::WaitingForStock]);

        if ($rows === []) {
            return [];
        }

        $posIds = collect($rows)->where('type', 'pos')->pluck('id')->all();
        $onlineIds = collect($rows)->where('type', 'online')->pluck('id')->all();

        $allocations = InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()
            ->where(function ($q) use ($posIds, $onlineIds): void {
                $q->where(fn ($q2) => $q2->where('source_type', (new PosOrder())->getMorphClass())->whereIn('source_id', $posIds))
                    ->orWhere(fn ($q2) => $q2->where('source_type', (new Order())->getMorphClass())->whereIn('source_id', $onlineIds));
            })
            ->with(['warehouse', 'reservations.inventoryItem', 'reservations.transfer'])
            ->get()
            ->keyBy(fn (InventoryAllocation $a) => $a->source_type . ':' . $a->source_id));

        // Needed to cross-reference each reservation (and any order line
        // that never got one at all) against its ORIGINAL external
        // product/variant ids and mapping outcome — that lives on the order
        // line, not on InventoryReservation.
        $posOrders = PosOrder::withoutTenancy(fn () => PosOrder::query()->with('items')->whereIn('id', $posIds)->get())->keyBy('id');
        $onlineOrders = Order::withoutTenancy(fn () => Order::query()->with('platformConnection')->whereIn('id', $onlineIds)->get())->keyBy('id');

        return collect($rows)->map(function (array $row) use ($allocations, $posOrders, $onlineOrders) {
            $morphClass = $row['type'] === 'pos' ? (new PosOrder())->getMorphClass() : (new Order())->getMorphClass();
            $allocation = $allocations->get($morphClass . ':' . $row['id']);
            $order = $row['type'] === 'pos' ? $posOrders->get($row['id']) : $onlineOrders->get($row['id']);
            $row['shortage'] = $this->buildShortageSummary($allocation, $order);

            return $row;
        })->all();
    }

    /** @return array<string, mixed>|null */
    private function buildShortageSummary(?InventoryAllocation $allocation, Order|PosOrder|null $order): ?array
    {
        if ($allocation === null) {
            return null;
        }

        $waiting = WaitingStockState::forReservations($allocation->reservations);

        $sourcePlatform = $order instanceof PosOrder ? 'pos' : $order?->platformConnection?->platform;
        $orderLines = $order !== null ? OrderLineItems::for($order) : [];
        // Only lines that DID resolve to an item — keyed for O(1) lookup
        // when annotating each reservation below.
        $lineByItemId = collect($orderLines)->filter(fn (array $l) => $l['inventory_item_id'] !== null)->keyBy('inventory_item_id');

        $lines = $allocation->reservations->map(function ($reservation) use ($allocation, $lineByItemId, $order, $sourcePlatform) {
            $item = $reservation->inventoryItem;

            // Live available-elsewhere figure — read straight from
            // WarehouseInventoryBalance (the source of truth), never a
            // stale snapshot, so "Request transfer" only ever offers to
            // pull from stock that genuinely still exists right now.
            $elsewhere = $item === null ? 0 : (int) WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()
                ->where('inventory_item_id', $item->id)
                ->where('warehouse_id', '!=', $reservation->warehouse_id)
                ->get()
                ->sum(fn (WarehouseInventoryBalance $b) => $b->available()));

            $balance = $item === null ? null : $this->inventory->balance($item, $allocation->warehouse);

            // Debug-safe diagnostics for admin/support: which catalog record
            // this shortage is actually pinned to, so a mismatch (e.g. the
            // visible product name looks right but the underlying
            // inventory_item_id is a different, stale record) is visible
            // instead of silently confusing.
            $variantLink = $item === null ? null : VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()
                ->where('inventory_item_id', $item->id)->first());
            $productLink = ($item === null || $variantLink !== null) ? null : ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()
                ->where('inventory_item_id', $item->id)->first());
            $metadata = $reservation->metadata ?? [];
            $matchedLine = $item !== null ? $lineByItemId->get($item->id) : null;

            return [
                'reservation_id' => $reservation->id,
                'inventory_item_id' => $item?->id,
                'name' => $item?->name,
                'sku' => $item?->sku,
                'needed' => (int) $reservation->requested_quantity,
                'available_in_target' => $balance?->available() ?? 0,
                'missing' => (int) $reservation->shortage_quantity,
                'available_elsewhere' => $elsewhere,
                'transfer' => $reservation->transfer === null ? null : [
                    'id' => $reservation->transfer->id,
                    'reference' => $reservation->transfer->reference,
                    'status' => $reservation->transfer->status,
                ],
                'restock_requested_at' => $reservation->restock_requested_at?->toIso8601String(),
                'can_request_transfer' => (int) $reservation->shortage_quantity > 0
                    && $reservation->transfer === null
                    && $elsewhere > 0,
                'debug' => [
                    'shortage_id' => $reservation->id,
                    'product_id' => $productLink?->product_id ?? null,
                    'product_variant_id' => $variantLink?->product_variant_id ?? null,
                    'target_warehouse_id' => $allocation->warehouse_id,
                    'target_warehouse_name' => $allocation->warehouse?->name,
                    'on_hand' => $balance?->on_hand ?? 0,
                    'reserved' => $balance?->reserved ?? 0,
                    'transfer_reserved' => $balance?->transfer_reserved ?? 0,
                    'status' => $reservation->status,
                    'last_rechecked_at' => $metadata['last_rechecked_at'] ?? null,
                    'last_recheck_message' => $metadata['last_recheck_message'] ?? null,
                    'source_platform' => $sourcePlatform,
                    'platform_connection_id' => $order instanceof Order ? $order->platform_connection_id : null,
                    'external_product_id' => $matchedLine['external_product_id'] ?? null,
                    'external_variant_id' => $matchedLine['external_variant_id'] ?? null,
                    'mapping_source' => $matchedLine['mapping_source'] ?? null,
                ],
            ];
        })->all();

        // Order lines that never got a reservation at all — dropped
        // entirely because the resolver couldn't map them to any local
        // InventoryItem (never a "silent" no-stock line: this is exactly
        // the "SKU exists but inventory_item_id is empty" shape the mapping
        // spec calls out). No reservation_id exists for these; the
        // frontend must key on something else (sku/index).
        $reservedItemIds = $allocation->reservations->pluck('inventory_item_id')->filter()->all();
        $unmappedLines = collect($orderLines)
            ->filter(fn (array $l) => $l['unmapped'] && ($l['inventory_item_id'] === null || ! in_array($l['inventory_item_id'], $reservedItemIds, true)))
            ->map(fn (array $l) => [
                'reservation_id' => null,
                'inventory_item_id' => null,
                'name' => $l['name'],
                'sku' => $l['sku'],
                'needed' => (int) $l['quantity'],
                'available_in_target' => 0,
                'missing' => (int) $l['quantity'],
                'available_elsewhere' => 0,
                'transfer' => null,
                'restock_requested_at' => null,
                'can_request_transfer' => false,
                'debug' => [
                    'shortage_id' => null,
                    'product_id' => $l['product_id'],
                    'product_variant_id' => $l['variant_id'],
                    'target_warehouse_id' => $allocation->warehouse_id,
                    'target_warehouse_name' => $allocation->warehouse?->name,
                    'on_hand' => 0,
                    'reserved' => 0,
                    'transfer_reserved' => 0,
                    'status' => 'unmapped',
                    'last_rechecked_at' => null,
                    'last_recheck_message' => $l['mapping_message'] ?? 'Order line is not linked to a local inventory item.',
                    'source_platform' => $sourcePlatform,
                    'platform_connection_id' => $order instanceof Order ? $order->platform_connection_id : null,
                    'external_product_id' => $l['external_product_id'],
                    'external_variant_id' => $l['external_variant_id'],
                    'mapping_source' => $l['mapping_source'],
                ],
            ])->values()->all();

        return [
            'warehouse_id' => $allocation->warehouse_id,
            'warehouse_name' => $allocation->warehouse?->name,
            'notes' => $allocation->notes,
            'state' => $waiting['state'],
            'state_label' => $waiting['label'],
            'lines' => [...$lines, ...$unmappedLines],
        ];
    }

    /**
     * Resolve one Order|PosOrder for a Waiting for Stock action — scoped
     * exactly like queue(): the order's allocation must sit at a warehouse
     * this viewer's organization operates, AND the order's own store must be
     * one they hold $permission (or orders.manage) on. Null on any mismatch
     * — the controller turns that into a 404, never leaking whether the
     * order exists at all.
     */
    public function findWaitingOrder(User $user, string $type, string $id, string $permission = 'orders.fulfil'): Order|PosOrder|null
    {
        $warehouseIds = $this->operatorWarehouses($user)->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return null;
        }

        $model = match ($type) {
            'pos' => PosOrder::withoutTenancy(fn () => PosOrder::query()
                ->whereHas('inventoryAllocation', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
                ->find($id)),
            'online' => Order::withoutTenancy(fn () => Order::query()
                ->whereHas('inventoryAllocation', fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
                ->find($id)),
            default => null,
        };

        if ($model === null) {
            return null;
        }

        $store = Store::query()->find($model->store_id);

        if ($store === null || (! $user->hasStorePermission($store, $permission) && ! $user->hasStorePermission($store, 'orders.manage'))) {
            return null;
        }

        return $model;
    }

    /**
     * "Recheck stock" — reruns WaitingStockReallocationService for every
     * still-open shortage line on this order's allocation. Fair by design:
     * this always processes the SAME warehouse-wide FIFO queue any other
     * waiting order for the same item would, so clicking it on one order
     * may resolve an older order first — exactly as it should.
     *
     * Before rechecking each line, attempts a conservative self-repair
     * (WarehouseAllocationService::repairReservationItem) for a shortage
     * that was mis-resolved to the wrong inventory item at confirm time —
     * see the CatalogInventoryService::resolve() fix. Never silently does
     * nothing: every line reports either a resolution or a concrete reason
     * it is still blocked.
     *
     * @return array<int, array{reservations_checked?: int, resolved: int, partially_resolved?: int, reason: ?string, repaired?: bool}>
     */
    public function recheckOrder(Order|PosOrder $order, ?User $actor = null): array
    {
        $allocation = InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()
            ->where('source_type', $order->getMorphClass())
            ->where('source_id', $order->getKey())
            ->with('warehouse')
            ->first());

        if ($allocation === null) {
            return [['resolved' => 0, 'reason' => 'No inventory allocation exists for this order yet.']];
        }

        if ($allocation->warehouse === null) {
            return [['resolved' => 0, 'reason' => 'Allocation has no target warehouse.']];
        }

        $openReservations = InventoryReservation::withoutOrganizationTenancy(fn () => $allocation->reservations()
            ->where('shortage_quantity', '>', 0)->get());

        $results = [];

        foreach ($openReservations->unique('inventory_item_id') as $reservation) {
            $repairMessage = $this->allocations->repairReservationItem($order, $reservation);

            if ($repairMessage !== null) {
                $reservation->unsetRelation('inventoryItem')->refresh();
            }

            $item = $reservation->inventoryItem;

            if ($item === null) {
                $reason = $repairMessage ?? 'This line has no linked inventory item — order line is unmapped.';
                $this->recordRecheckOutcome($reservation, $reason);
                $results[] = ['resolved' => 0, 'reason' => $reason, 'repaired' => $repairMessage !== null];
                continue;
            }

            $result = $this->reallocation->recheck($item, $allocation->warehouse, $actor);

            $reason = match (true) {
                $result['resolved'] > 0 => null,
                $result['partially_resolved'] > 0 => 'Partial stock only — still short after recheck.',
                default => $this->explainStillBlocked($item, $allocation->warehouse),
            };

            $this->recordRecheckOutcome($reservation, $repairMessage ?? $reason ?? 'Resolved.');

            $results[] = [...$result, 'reason' => $repairMessage ?? $reason, 'repaired' => $repairMessage !== null];
        }

        // An order line that was dropped entirely at confirm time (never got
        // a reservation of any kind — the exact "SKU exists but
        // inventory_item_id is empty" shape) has nothing for the loop above
        // to find or repair. reconcileAllocation() re-derives requirements()
        // from the order's CURRENT line resolution and backfills a
        // reservation for anything still missing.
        $createdReservations = $this->allocations->reconcileAllocation($order, $allocation);

        foreach ($createdReservations as $reservation) {
            $item = $reservation->inventoryItem;

            if ($item === null) {
                continue;
            }

            $reason = (int) $reservation->shortage_quantity > 0
                ? $this->explainStillBlocked($item, $allocation->warehouse)
                : null;

            $this->recordRecheckOutcome(
                $reservation,
                'Missing order line mapping repaired — a reservation was created from the current resolution.' . ($reason !== null ? " {$reason}" : ''),
            );

            $results[] = [
                'reservations_checked' => 1,
                'resolved' => (int) $reservation->shortage_quantity === 0 ? 1 : 0,
                'partially_resolved' => 0,
                'reason' => $reason,
                'repaired' => true,
            ];
        }

        if ($createdReservations !== []) {
            // Covers the case where reconciling this one missing line was
            // the ONLY thing still keeping the order out of Pick & Pack —
            // WaitingStockReallocationService::recheck() (used by the loop
            // above) triggers this itself per-reservation, but a freshly
            // created, already-fully-reserved reservation never passes
            // through that path at all.
            $this->completion->syncIfComplete($allocation);
        }

        if ($results === []) {
            return [['resolved' => 0, 'reason' => 'No open shortage lines — nothing to recheck.']];
        }

        return $results;
    }

    /** Why a shortage line is still blocked after a recheck — surfaced verbatim to the UI, never a silent no-op. */
    private function explainStillBlocked(InventoryItem $item, Warehouse $warehouse): string
    {
        $available = $this->inventory->balance($item, $warehouse)->available();

        if ($available > 0) {
            return "Only {$available} unit(s) available at {$warehouse->name} — still not enough to cover the shortage.";
        }

        $elsewhere = (int) WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()
            ->where('inventory_item_id', $item->id)
            ->where('warehouse_id', '!=', $warehouse->id)
            ->get()
            ->sum(fn (WarehouseInventoryBalance $b) => $b->available()));

        return $elsewhere > 0
            ? "No stock at {$warehouse->name}, but {$elsewhere} unit(s) available elsewhere — request a transfer."
            : 'No available stock anywhere for this item yet.';
    }

    /** Persists a per-line recheck outcome so the UI can show "last checked / why" instead of a bare number. */
    private function recordRecheckOutcome(InventoryReservation $reservation, string $message): void
    {
        $reservation->refresh();
        $metadata = $reservation->metadata ?? [];
        $metadata['last_recheck_message'] = $message;
        $metadata['last_rechecked_at'] = now()->toIso8601String();
        $reservation->update(['metadata' => $metadata]);
    }

    /**
     * "Request transfer" — for one shortage line, or every open line on the
     * order when no specific one is given. Throws (ValidationException) when
     * no other warehouse actually has the stock — the controller turns that
     * into a flashed error, never a fabricated transfer.
     */
    public function requestTransferForOrder(Order|PosOrder $order, ?User $actor = null, ?string $reservationId = null): void
    {
        $allocation = InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()
            ->where('source_type', $order->getMorphClass())
            ->where('source_id', $order->getKey())
            ->with(['reservations' => fn ($q) => $q->where('shortage_quantity', '>', 0)])
            ->first());

        if ($allocation === null) {
            throw ValidationException::withMessages(['order' => 'This order has no open shortage.']);
        }

        $reservations = $reservationId !== null
            ? $allocation->reservations->where('id', $reservationId)
            : $allocation->reservations;

        if ($reservations->isEmpty()) {
            throw ValidationException::withMessages(['order' => 'This order has no open shortage.']);
        }

        foreach ($reservations as $reservation) {
            $this->allocations->requestTransferForShortage($reservation, $actor);
        }
    }

    /**
     * "Mark restock requested" — a supervisor's manual acknowledgement that
     * no warehouse can currently fill this shortage and a real restock
     * (purchase, production, …) is needed. Purely a visibility/status flag;
     * never moves stock and never creates a fake transfer.
     */
    public function markRestockRequested(Order|PosOrder $order): void
    {
        $allocation = InventoryAllocation::withoutOrganizationTenancy(fn () => InventoryAllocation::query()
            ->where('source_type', $order->getMorphClass())
            ->where('source_id', $order->getKey())
            ->first());

        if ($allocation === null) {
            return;
        }

        \App\Models\InventoryReservation::withoutOrganizationTenancy(fn () => $allocation->reservations()
            ->where('shortage_quantity', '>', 0)
            ->whereNull('restock_requested_at')
            ->update(['restock_requested_at' => now()]));
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
