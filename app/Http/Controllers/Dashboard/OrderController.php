<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\DeliveryConnection;
use App\Models\DeliveryNote;
use App\Models\FulfillmentDocument;
use App\Models\AgentActivityEvent;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\User;
use App\Services\Activity\AgentActivityRecorder;
use App\Services\Delivery\DeliveryCityMappingResolver;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Pos\DocumentGenerationService;
use App\Support\OrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

// Note: show() method appended below

class OrderController extends Controller
{
    /**
     * Unified orders list — POS and online in one filterable, paginated table.
     *
     * The two channels live in separate tables, so they're normalized to a common
     * row shape (OrderPresenter::*Row) and merged in memory. Volume is low enough
     * that fetching a recent window of each and paginating the merged collection
     * is simpler and cheaper than a cross-table UNION with its own pagination.
     */
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Orders/Index', [
                'orders'      => ['data' => [], 'links' => [], 'total' => 0],
                'stats'       => ['today' => 0, 'week' => 0, 'month' => 0],
                'filters'     => [],
                'connections' => [],
            ]);
        }

        $filters = [
            'search'     => $request->input('search'),
            'status'     => $request->input('status'),
            'source'     => $request->input('source'), // '', 'pos' or 'online'
            // Platform/connection are a finer lens than source: both imply
            // "online" (POS has neither), but source itself keeps its exact
            // original meaning/values for backward compatibility.
            'platform'   => $request->input('platform'), // '', 'shopify', 'woocommerce', 'youcan', 'manual'
            'connection' => $request->input('connection'), // platform_connection_id
        ];

        $wantsOnlineOnly = $filters['source'] === 'online' || filled($filters['platform']) || filled($filters['connection']);
        $wantsPosOnly    = $filters['source'] === 'pos';

        $pos = collect();
        if (! $wantsOnlineOnly) {
            $pos = PosOrder::query()
                ->where('store_id', $store->id)
                ->with('items')
                ->latest()
                ->limit(500)
                ->get()
                ->map(fn (PosOrder $o) => OrderPresenter::posRow($o));
        }

        $online = collect();
        if (! $wantsPosOnly) {
            $online = Order::query()
                ->where('store_id', $store->id)
                ->when(filled($filters['platform']), fn ($q) => $filters['platform'] === 'manual'
                    ? $q->where('source_type', 'manual')
                    : $q->where('source_platform', $filters['platform']))
                ->when(filled($filters['connection']), fn ($q) => $q->where('platform_connection_id', $filters['connection']))
                ->latest()
                ->limit(500)
                ->get()
                ->map(fn (Order $o) => OrderPresenter::onlineRow($o));
        }

        $rows = $pos->concat($online)
            ->when($request->filled('status'), fn ($c) => $c->where('status', $filters['status']))
            ->when($request->filled('search'), function ($c) use ($filters) {
                $term = mb_strtolower(trim((string) $filters['search']));

                return $c->filter(fn (array $r) => str_contains(
                    mb_strtolower(implode(' ', array_filter([
                        $r['reference'] ?? null,
                        $r['customer_name'] ?? null,
                        $r['customer_email'] ?? null,
                    ]))),
                    $term,
                ));
            })
            ->sortByDesc('created_at')
            ->values();

        $perPage = 20;
        $page    = max(1, (int) $request->input('page', 1));

        $orders = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $posBase    = fn () => PosOrder::query()->where('store_id', $store->id);
        $onlineBase = fn () => Order::query()->where('store_id', $store->id);

        $stats = [
            'today' => $posBase()->whereDate('created_at', today())->count()
                     + $onlineBase()->whereDate('created_at', today())->count(),
            'week'  => $posBase()->where('created_at', '>=', now()->startOfWeek())->count()
                     + $onlineBase()->where('created_at', '>=', now()->startOfWeek())->count(),
            'month' => $posBase()->where('created_at', '>=', now()->startOfMonth())->count()
                     + $onlineBase()->where('created_at', '>=', now()->startOfMonth())->count(),
        ];

        return Inertia::render('Dashboard/Orders/Index', [
            'store'       => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'orders'      => $orders,
            'stats'       => $stats,
            'filters'     => $filters,
            // Only rendered as a filter when the store actually has more than
            // one connection — a single-connection store already gets the
            // same result from the platform filter above.
            'connections' => $store->connections()
                ->where('status', 'active')
                ->get(['id', 'platform', 'label'])
                ->map(fn ($c) => ['id' => $c->id, 'platform' => $c->platform, 'label' => $c->label ?: ucfirst($c->platform)])
                ->values(),
        ]);
    }

    /** Unified multi-channel Order Management board (POS + online). */
    public function manage(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Orders/Manage', ['store' => null, 'orders' => []]);
        }

        $posModels = PosOrder::query()
            ->where('store_id', $store->id)
            ->with(['items', 'store:id,currency', 'shippingCity:id,name'])
            ->latest()
            ->limit(300)
            ->get();

        $onlineModels = Order::query()
            ->where('store_id', $store->id)
            ->with(['store:id,currency', 'shippingCity:id,name'])
            ->latest()
            ->limit(300)
            ->get();

        // Same decoration pattern as DepartmentController::queueFor() — one
        // batch lookup of every assignee across both channels, read-only,
        // no workflow change. `assigned_to`/`assignee_name` let the board's
        // summary strip and filter bar show "assigned" without inventing data.
        $assignees = User::whereIn(
            'id',
            $posModels->pluck('assigned_to')->merge($onlineModels->pluck('assigned_to'))->filter()->unique(),
        )->pluck('name', 'id');

        $viewer = $request->user();

        $decorate = function (array $row, $model) use ($assignees, $viewer): array {
            $row['assigned_to']   = $model->assigned_to;
            $row['assignee_name'] = $model->assigned_to ? ($assignees[$model->assigned_to] ?? null) : null;

            return [...$row, ...OrderPresenter::claimState($model, $viewer, $row['assignee_name'])];
        };

        $pos = $posModels->map(fn (PosOrder $o) => $decorate(OrderPresenter::pos($o), $o));
        $online = $onlineModels->map(fn (Order $o) => $decorate(OrderPresenter::online($o), $o));

        // Merge both channels into one recency-sorted list. Tabs/source/search
        // are applied client-side for instant switching (fine at this volume).
        $orders = $pos->concat($online)->sortByDesc('created_at')->values()->all();

        return Inertia::render('Dashboard/Orders/Manage', [
            'store'  => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'orders' => $orders,
            'cities' => City::query()->where('country_code', 'MA')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'region']),
        ]);
    }

    /**
     * Advance an order through the fulfillment workflow.
     *
     * The transition itself — legality, stock side-effects, the legacy `status`
     * projection, the audit entry — belongs to OrderWorkflowService; this only
     * resolves the model, checks the department permission, and turns a rejected
     * move into a toast.
     */
    public function updateStatus(
        Request $request,
        string $type,
        string $id,
        OrderWorkflowService $workflow,
        AgentActivityRecorder $activity,
    ): RedirectResponse {
        $user  = $request->user();
        $store = $user->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(FulfillmentStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            'shipping_city_id' => ['nullable', 'string', 'exists:cities,id'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);
        $target = FulfillmentStatus::from($validated['status']);

        $model = match ($type) {
            'pos'    => PosOrder::where('store_id', $store->id)->findOrFail($id),
            'online' => Order::where('store_id', $store->id)->findOrFail($id),
            default  => abort(404),
        };

        $current = $model->fulfillment_status ?? FulfillmentStatus::Pending;

        // Confirmation Desk claim gate: confirming or cancelling an order
        // still sitting in the confirmation queue requires the CURRENT USER
        // to hold the claim — permission alone (checked below) is not
        // enough, or any agent with orders.confirm could act on an order
        // another agent is actively handling. orders.manage is the existing
        // supervisor-override escape hatch. Scoped to current === Pending
        // only, so every other department's claim/permission behavior is
        // completely unchanged.
        if ($current === FulfillmentStatus::Pending && in_array($target, [FulfillmentStatus::Confirmed, FulfillmentStatus::Cancelled], true)) {
            abort_unless(
                $user->can('orders.manage') || $model->assigned_to === $user->id,
                403,
                $model->assigned_to === null
                    ? 'Claim this order before confirming or cancelling it.'
                    : 'This order is claimed by another agent.',
            );
        }

        // Each department owns its own moves; `orders.manage` is the coarse
        // permission that covers all of them, so existing roles keep working.
        abort_unless(
            $user->can($target->permission($current)) || $user->can('orders.manage'),
            403,
        );

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($model, $target, $user, $validated, $workflow, $activity, $store): void {
                $addressChanged = false;

                if ($target === FulfillmentStatus::Confirmed) {
                    $priorCityId = $model->shipping_city_id;
                    $priorAddress = $model instanceof Order ? $model->confirmed_shipping_address : $model->delivery_address;
                    // The platform reported a city, but it doesn't match any
                    // known city (city_recognized: false on the presenter) —
                    // confirming without picking one would allocate against
                    // the wrong/no warehouse silently. This does NOT make
                    // city mandatory in general: an order with no city
                    // information at all confirms exactly as before.
                    if ($model instanceof Order && empty($validated['shipping_city_id']) && $model->shipping_city_id === null) {
                        $rawCity = \App\Support\OrderAddressSummary::extract($model)['city'];

                        if ($rawCity !== null && \App\Support\OrderAddressSummary::matchCity($rawCity) === null) {
                            throw ValidationException::withMessages([
                                'shipping_city_id' => "The platform reported city \"{$rawCity}\", which isn't in the city list — select or normalize the city before confirming.",
                            ]);
                        }
                    }

                    if ($model instanceof Order) {
                        $model->update([
                            'shipping_city_id' => $validated['shipping_city_id'] ?? $model->shipping_city_id,
                            'confirmed_shipping_address' => $validated['shipping_address'] ?? $model->confirmed_shipping_address
                                ?? \App\Support\OrderAddressSummary::extract($model)['address1'],
                            'customer_name' => $validated['customer_name'] ?? $model->customer_name,
                            'customer_phone' => $validated['customer_phone'] ?? $model->customer_phone,
                        ]);
                    } elseif ($model instanceof PosOrder) {
                        $model->update([
                            'shipping_city_id' => $validated['shipping_city_id'] ?? $model->shipping_city_id,
                            'delivery_address' => $validated['shipping_address'] ?? $model->delivery_address,
                            'customer_name' => $validated['customer_name'] ?? $model->customer_name,
                            'customer_phone' => $validated['customer_phone'] ?? $model->customer_phone,
                        ]);
                    }

                    $newAddress = $model instanceof Order ? $model->confirmed_shipping_address : $model->delivery_address;
                    $addressChanged = $model->shipping_city_id !== $priorCityId || $newAddress !== $priorAddress;
                }

                $workflow->transition($model->refresh(), $target, $user, $validated['reason'] ?? null);

                // Agent activity ledger — a real, agent-entered correction to
                // the shipping city/address made while confirming, distinct
                // from the confirmation.confirmed event itself. Additive
                // observation only; never affects the transition above.
                if ($addressChanged && $store !== null) {
                    $activity->record($user, $store, AgentActivityEvent::CONFIRMATION_ADDRESS_UPDATED, 'confirmation', [
                        'subject' => $model,
                        'order_id' => $model->getKey(),
                    ]);
                }
            });
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', "Order moved to {$target->label()}.");
    }

    public function show(Request $request, PosOrder $order): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $order->store_id !== $store->id, 403);

        $order->load(['items', 'cashier:id,name', 'session:id,opening_balance,opened_at', 'invoice']);

        // The raw model stays `order` (existing page reads it directly) —
        // `inventory` is the new O7 addition, built from the same presenter
        // online orders already use, so both detail pages stay consistent.
        $presented = OrderPresenter::pos($order);

        return Inertia::render('Dashboard/Orders/Show', [
            'order'      => $order,
            'store'      => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'invoice'    => $order->invoice ? $order->invoice->only(['id', 'invoice_number', 'status']) : null,
            'canInvoice' => Gate::allows('invoices.issue'),
            'inventory'  => [
                'status' => $presented['inventory_status'],
                'allocation' => $presented['allocation'],
                'unmapped_lines' => $presented['unmapped_lines'],
            ],
            'source'     => [
                'source_type' => $presented['source_type'],
                'source_platform' => $presented['source_platform'],
                'platform_label' => $presented['platform_label'],
                'connection_label' => $presented['connection_label'],
                'store_domain' => $presented['store_domain'],
                'external_order_number' => $presented['external_order_number'],
                'badge_label' => $presented['badge_label'],
            ],
        ]);
    }

    /** Stream a freshly rendered 80mm thermal receipt for the POS order. */
    public function receipt(Request $request, PosOrder $order, DocumentGenerationService $documents): \Illuminate\Http\Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $order->store_id !== $store->id, 403);

        return response($documents->renderReceipt($order), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$order->receipt_number}-receipt.pdf\"",
        ]);
    }

    /**
     * Detail page for an ONLINE order — the online counterpart of show().
     *
     * Online orders live in their own table with the item list stored as JSON,
     * so they can't reuse the POS-bound show()/receipt(). This gives them the
     * same reach: view details, generate the formal A4 invoice, and print a
     * thermal delivery slip.
     */
    public function showOnline(Request $request, Order $order): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $order->store_id !== $store->id, 403);

        $order->load(['invoice', 'shipment.events']);

        return Inertia::render('Dashboard/Orders/ShowOnline', [
            'order'      => OrderPresenter::online($order) + ['notes' => $order->notes],
            'store'      => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'invoice'    => $order->invoice ? $order->invoice->only(['id', 'invoice_number', 'status']) : null,
            'canInvoice' => Gate::allows('invoices.issue'),
            'shipment'   => $this->shipmentProp($order),
            'ozon_city_resolution' => $this->ozonCityResolutionProp($order, $store),
            'fulfillment_documents' => $this->fulfillmentDocumentsProp($order),
            'can_view_fulfillment_documents' => Gate::allows('fulfillment.documents.view') || Gate::allows('orders.manage'),
            'can_print_pick_ticket' => Gate::allows('fulfillment.documents.print') || Gate::allows('orders.manage'),
            'pick_ticket_eligible' => app(\App\Services\Documents\PickPackTicketService::class)->isEligible($order),
        ]);
    }

    /**
     * Carrier labels / BL / fallback labels stored for this order's Ozon (or
     * any provider) shipment and its Bon de Livraison — read-only, for the
     * order-detail Documents card. Never triggers any provider call.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fulfillmentDocumentsProp(Order $order): array
    {
        $shipment = $order->shipment;

        if ($shipment === null) {
            return [];
        }

        $noteId = null;

        if (filled($shipment->delivery_note_ref)) {
            $noteId = DeliveryNote::query()
                ->where('store_id', $order->store_id)
                ->where('provider_ref', $shipment->delivery_note_ref)
                ->value('id');
        }

        $shipmentMorph = $shipment->getMorphClass();
        $noteMorph = (new DeliveryNote)->getMorphClass();

        return FulfillmentDocument::query()
            ->where('store_id', $order->store_id)
            ->where(function ($q) use ($shipment, $noteId, $shipmentMorph, $noteMorph) {
                $q->where(fn ($q2) => $q2->where('documentable_type', $shipmentMorph)->where('documentable_id', $shipment->id));

                if ($noteId !== null) {
                    $q->orWhere(fn ($q2) => $q2->where('documentable_type', $noteMorph)->where('documentable_id', $noteId));
                }
            })
            ->latest()
            ->get()
            ->map(fn (FulfillmentDocument $d) => [
                'id' => $d->id,
                'type' => $d->document_type?->value,
                'label' => $d->label,
                'variant' => data_get($d->metadata, 'variant'),
                'provider_code' => $d->provider_code,
                'status' => $d->status?->value,
                'status_label' => $d->status?->label(),
                'downloadable' => $d->is_downloadable,
                'download_url' => $d->download_url,
                'created_at' => $d->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Debug/visibility aid for "Send to Ozon" city-mapping issues: what city
     * text the order carries, which internal city (if any) it matches, and
     * which Ozon city it would resolve to — shown whether or not the order
     * has been sent yet, and independent of the `shipment` prop above.
     *
     * @return array<string, mixed>|null
     */
    private function ozonCityResolutionProp(Order $order, \App\Models\Store $store): ?array
    {
        $connection = DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->first();

        if ($connection === null) {
            return null;
        }

        $resolution = app(DeliveryCityMappingResolver::class)->resolve($order, $connection);

        return [
            'raw_city' => $resolution['raw_city_text'],
            'internal_city_name' => $resolution['internal_city_name'],
            'provider_city_name' => $resolution['provider_city_name'],
            'resolved' => $resolution['resolved'],
            'resolution_source' => $resolution['resolution_source'],
            'suggested_internal_city_name' => $resolution['suggested_internal_city_name'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function shipmentProp(Order $order): ?array
    {
        $shipment = $order->shipment;

        if ($shipment === null) {
            return null;
        }

        return [
            'id' => $shipment->id,
            'provider' => $shipment->provider_code,
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->status,
            'provider_status' => $shipment->provider_status,
            'sent_at' => $shipment->sent_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'last_update' => $shipment->updated_at?->toIso8601String(),
            'events' => $shipment->events->map(fn ($e) => [
                'normalized_status' => $e->normalized_status,
                'provider_status' => $e->provider_status,
                'message' => $e->message,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** Stream a freshly rendered 80mm thermal receipt for the online order. */
    public function receiptOnline(Request $request, Order $order, DocumentGenerationService $documents): \Illuminate\Http\Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $order->store_id !== $store->id, 403);

        return response($documents->renderOnlineReceipt($order), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$order->order_number}-receipt.pdf\"",
        ]);
    }
}
