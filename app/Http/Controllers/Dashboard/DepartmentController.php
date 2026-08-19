<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\DispatchService;
use App\Services\Orders\OrderAssignmentService;
use App\Services\Pos\DocumentGenerationService;
use App\Support\DepartmentRegistry;
use App\Support\OrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Focused work queues, one per operational department.
 *
 * These are not a replacement for the unified board at /dashboard/orders/manage
 * — that stays the cross-department overview. A department dashboard shows one
 * team only the orders it can act on, with the actions that team actually
 * performs.
 */
class DepartmentController extends Controller
{
    public function __construct(
        private readonly OrderAssignmentService $assignments,
    ) {}

    /** Confirmation desk: the pending-confirmation queue. */
    public function confirmation(Request $request): Response
    {
        [$store, $user] = $this->context($request);

        if ($store === null) {
            return $this->empty('Confirmation');
        }

        $orders = $this->queueFor($store, 'confirmation');

        return Inertia::render('Dashboard/Departments/Confirmation', [
            ...$this->shared($store, $user, 'confirmation'),
            'orders' => $orders,
            'agents' => $this->assignments->workload($store, 'orders.confirm', $user, 'confirmation'),
            'cities' => City::query()->where('country_code', 'MA')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'region']),
            'stats'  => [
                'waiting'  => count(array_filter($orders, fn ($o) => $o['assigned_to'] === null)),
                'mine'     => count(array_filter($orders, fn ($o) => $o['assigned_to'] === $user->id)),
                'total'    => count($orders),
                'oldest'   => $orders[count($orders) - 1]['created_at'] ?? null,
            ],
        ]);
    }

    /** Pick & pack bench: confirmed online orders + delivery-bound POS orders. */
    public function packing(Request $request): Response
    {
        [$store, $user] = $this->context($request);

        if ($store === null) {
            return $this->empty('Packing');
        }

        $orders = $this->queueFor($store, 'fulfillment');

        return Inertia::render('Dashboard/Departments/Packing', [
            ...$this->shared($store, $user, 'fulfillment'),
            'orders' => $orders,
            'agents' => $this->assignments->workload($store, 'orders.fulfil', $user, 'fulfillment'),
            'stats'  => [
                'waiting_stock' => count(array_filter($orders, fn ($o) => $o['status'] === 'waiting_for_stock')),
                'to_pick'       => count(array_filter($orders, fn ($o) => in_array($o['status'], ['confirmed', 'ready_for_picking'], true))),
                'picking'       => count(array_filter($orders, fn ($o) => in_array($o['status'], ['picking', 'in_progress'], true))),
                'packing'       => count(array_filter($orders, fn ($o) => $o['status'] === 'packing')),
                'mine'          => count(array_filter($orders, fn ($o) => $o['assigned_to'] === $user->id)),
                'units'         => array_sum(array_map(
                    fn ($o) => array_sum(array_column($o['items'] ?? [], 'quantity')),
                    $orders,
                )),
            ],
        ]);
    }

    /** Dispatch board: packed orders awaiting a carrier, plus what is in flight. */
    public function dispatch(Request $request): Response
    {
        [$store, $user] = $this->context($request);

        if ($store === null) {
            return $this->empty('Dispatch');
        }

        $orders = $this->queueFor($store, 'delivery');

        // Index shipments by order so each queue row carries its own tracking.
        $shipments = OrderShipment::query()
            ->where('store_id', $store->id)
            ->with('agent:id,name')
            ->latest()
            ->limit(300)
            ->get();

        $byOrder = $shipments->keyBy(fn (OrderShipment $s) => $s->shippable_type . ':' . $s->shippable_id);

        $orders = array_map(function (array $o) use ($byOrder) {
            $model = $o['type'] === 'pos' ? PosOrder::class : Order::class;
            $s     = $byOrder->get($model . ':' . $o['id']);

            $o['shipment'] = $s === null ? null : [
                'id'                 => $s->id,
                'reference'          => $s->reference,
                'status'             => $s->status,
                'carrier_type'       => $s->carrier_type,
                'carrier_label'      => $s->carrierLabel(),
                'tracking_number'    => $s->tracking_number,
                'tracking_url'       => $s->tracking_url,
                'manifest_reference' => $s->manifest_reference,
                'dispatched_at'      => $s->dispatched_at?->toIso8601String(),
            ];

            return $o;
        }, $orders);

        return Inertia::render('Dashboard/Departments/Dispatch', [
            ...$this->shared($store, $user, 'delivery'),
            'orders'    => $orders,
            'couriers'  => $this->knownCouriers($store),
            // The internal-agent picklist must list DRIVERS (orders.deliver), not
            // fellow dispatchers — a dispatcher hands a parcel to a delivery agent.
            'agents'    => $this->assignments->workload($store, 'orders.deliver', $user, 'delivery'),
            'manifests' => app(DispatchService::class)->manifests($store),
            'stats'    => [
                'awaiting'   => count(array_filter($orders, fn ($o) => $o['shipment'] === null)),
                'in_flight'  => $shipments->where('status', OrderShipment::STATUS_DISPATCHED)->count(),
                'delivered'  => $shipments->where('status', OrderShipment::STATUS_DELIVERED)->count(),
                'failed'     => $shipments->where('status', OrderShipment::STATUS_FAILED)->count(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /** Claim the longest-waiting unassigned order in this department. */
    public function takeNext(Request $request, string $phase): RedirectResponse
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);
        $this->authorizePhase($user, $store, $phase);

        $order = $this->assignments->takeNext($store, $user, $phase);

        return $order === null
            ? back()->with('warning', 'The queue is empty — nothing left to take.')
            : back()->with('success', 'Order assigned to you.');
    }

    public function claim(Request $request, string $type, string $id): RedirectResponse
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);

        $order = $this->resolveOrder($store, $type, $id);
        $this->authorizePhase($user, $store, ($order->fulfillment_status ?? FulfillmentStatus::Pending)->phase());

        try {
            $this->assignments->claim($order, $user);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Order assigned to you.');
    }

    public function release(Request $request, string $type, string $id): RedirectResponse
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);

        $order = $this->resolveOrder($store, $type, $id);
        $this->authorizePhase($user, $store, ($order->fulfillment_status ?? FulfillmentStatus::Pending)->phase());

        $this->assignments->release($order);

        return back()->with('success', 'Order returned to the queue.');
    }

    /** Assign a courier or internal agent to a packed order. */
    public function assignCarrier(Request $request, string $type, string $id, DispatchService $dispatch): RedirectResponse
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);
        abort_unless($this->can($user, $store, 'orders.dispatch'), 403);

        $validated = $request->validate([
            'carrier_type'       => ['required', Rule::in(OrderShipment::carrierTypes())],
            'carrier_name'       => ['nullable', 'string', 'max:120'],
            'tracking_number'    => ['nullable', 'string', 'max:120'],
            'tracking_url'       => ['nullable', 'url', 'max:500'],
            'agent_id'           => ['nullable', 'string'],
            'manifest_reference' => ['nullable', 'string', 'max:60'],
            'notes'              => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->resolveOrder($store, $type, $id);

        try {
            $shipment = $dispatch->assign($order, $validated, $user);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', "Dispatched with {$shipment->carrierLabel()}.");
    }

    public function markDelivered(Request $request, string $shipmentId, DispatchService $dispatch): RedirectResponse
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);
        abort_unless($this->can($user, $store, 'orders.dispatch'), 403);

        $shipment = OrderShipment::where('store_id', $store->id)->findOrFail($shipmentId);

        $dispatch->markDelivered($shipment, $user);

        return back()->with('success', 'Marked as delivered.');
    }

    public function markFailed(Request $request, string $shipmentId, DispatchService $dispatch): RedirectResponse
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);
        abort_unless($this->can($user, $store, 'orders.dispatch'), 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $shipment = OrderShipment::where('store_id', $store->id)->findOrFail($shipmentId);

        try {
            $dispatch->markFailed($shipment, $validated['reason'], $user);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('warning', 'Delivery failed — the order is now awaiting inspection.');
    }

    /** Stream the A4 carrier handover sheet for one manifest, inline for printing. */
    public function manifest(Request $request, string $reference, DispatchService $dispatch, DocumentGenerationService $documents): \Illuminate\Http\Response
    {
        [$store, $user] = $this->context($request);
        abort_if($store === null, 403);
        abort_unless($this->can($user, $store, 'orders.dispatch'), 403);

        try {
            $payload = $dispatch->gatherManifest($store, $reference);
        } catch (ValidationException) {
            abort(404);
        }

        return response($documents->renderManifest($payload), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$reference}.pdf\"",
        ]);
    }

    // -------------------------------------------------------------------------

    /** @return array{0: ?Store, 1: User} */
    private function context(Request $request): array
    {
        $user = $request->user();

        return [$user->getActiveStore(), $user];
    }

    /**
     * Every order whose status belongs to this phase, normalised through the
     * same presenter the unified board uses, plus the assignment fields the
     * department views add.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queueFor(Store $store, string $phase): array
    {
        $statuses = array_map(
            fn (FulfillmentStatus $s) => $s->value,
            array_filter(FulfillmentStatus::cases(), fn ($s) => $s->phase() === $phase),
        );

        $assignees = [];

        $decorate = function (array $row, $model) use (&$assignees): array {
            $row['assigned_to']   = $model->assigned_to;
            $row['assigned_at']   = $model->assigned_at?->toIso8601String();
            $row['assignee_name'] = $model->assigned_to ? ($assignees[$model->assigned_to] ?? null) : null;

            return $row;
        };

        $pos = PosOrder::query()
            ->where('store_id', $store->id)
            ->whereIn('fulfillment_status', $statuses)
            ->with(['items', 'store:id,currency', 'shippingCity', 'inventoryAllocation.warehouse', 'inventoryAllocation.city', 'inventoryAllocation.reservations'])
            ->oldest()
            ->limit(200)
            ->get();

        $online = Order::query()
            ->where('store_id', $store->id)
            ->whereIn('fulfillment_status', $statuses)
            ->with(['store:id,currency', 'shippingCity', 'inventoryAllocation.warehouse', 'inventoryAllocation.city', 'inventoryAllocation.reservations'])
            ->oldest()
            ->limit(200)
            ->get();

        // One lookup for every assignee across both channels.
        $assignees = User::whereIn(
            'id',
            $pos->pluck('assigned_to')->merge($online->pluck('assigned_to'))->filter()->unique(),
        )->pluck('name', 'id')->all();

        $rows = $pos->map(fn (PosOrder $o) => $decorate(OrderPresenter::pos($o), $o))
            ->concat($online->map(fn (Order $o) => $decorate(OrderPresenter::online($o), $o)));

        // Oldest first: a work queue is FIFO, unlike the recency-sorted board.
        return $rows->sortBy('created_at')->values()->all();
    }

    /** @return array<string, mixed> */
    private function shared(Store $store, User $user, string $phase): array
    {
        return [
            'store'       => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'departments' => DepartmentRegistry::visibleTo($user, $store),
            'phase'       => $phase,
        ];
    }

    private function empty(string $page): Response
    {
        return Inertia::render("Dashboard/Departments/{$page}", [
            'store' => null, 'orders' => [], 'agents' => [], 'stats' => [], 'departments' => [],
        ]);
    }

    private function resolveOrder(Store $store, string $type, string $id): Order|PosOrder
    {
        return match ($type) {
            'pos'    => PosOrder::where('store_id', $store->id)->findOrFail($id),
            'online' => Order::where('store_id', $store->id)->findOrFail($id),
            default  => abort(404),
        };
    }

    private function can(User $user, Store $store, string $permission): bool
    {
        return $user->hasStorePermission($store, $permission)
            || $user->hasStorePermission($store, 'orders.manage');
    }

    private function authorizePhase(User $user, Store $store, string $phase): void
    {
        abort_unless($this->can($user, $store, DepartmentRegistry::permissionFor($phase)), 403);
    }

    /**
     * Couriers this store has used before, so the dispatcher picks from a list
     * instead of retyping. Free text stays allowed — no courier registry yet.
     *
     * @return array<int, string>
     */
    private function knownCouriers(Store $store): array
    {
        return OrderShipment::query()
            ->where('store_id', $store->id)
            ->whereNotNull('carrier_name')
            ->distinct()
            ->orderBy('carrier_name')
            ->pluck('carrier_name')
            ->all();
    }
}
