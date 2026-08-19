<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Order;
use App\Models\PosOrder;
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
                'orders'  => ['data' => [], 'links' => [], 'total' => 0],
                'stats'   => ['today' => 0, 'week' => 0, 'month' => 0],
                'filters' => [],
            ]);
        }

        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'source' => $request->input('source'), // '', 'pos' or 'online'
        ];

        $pos = collect();
        if ($filters['source'] !== 'online') {
            $pos = PosOrder::query()
                ->where('store_id', $store->id)
                ->with('items')
                ->latest()
                ->limit(500)
                ->get()
                ->map(fn (PosOrder $o) => OrderPresenter::posRow($o));
        }

        $online = collect();
        if ($filters['source'] !== 'pos') {
            $online = Order::query()
                ->where('store_id', $store->id)
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
            'store'   => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'orders'  => $orders,
            'stats'   => $stats,
            'filters' => $filters,
        ]);
    }

    /** Unified multi-channel Order Management board (POS + online). */
    public function manage(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Orders/Manage', ['store' => null, 'orders' => []]);
        }

        $pos = PosOrder::query()
            ->where('store_id', $store->id)
            ->with(['items', 'store:id,currency', 'shippingCity:id,name'])
            ->latest()
            ->limit(300)
            ->get()
            ->map(fn (PosOrder $o) => OrderPresenter::pos($o));

        $online = Order::query()
            ->where('store_id', $store->id)
            ->with(['store:id,currency', 'shippingCity:id,name'])
            ->latest()
            ->limit(300)
            ->get()
            ->map(fn (Order $o) => OrderPresenter::online($o));

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

        // Each department owns its own moves; `orders.manage` is the coarse
        // permission that covers all of them, so existing roles keep working.
        abort_unless(
            $user->can($target->permission($current)) || $user->can('orders.manage'),
            403,
        );

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($model, $target, $user, $validated, $workflow): void {
                if ($target === FulfillmentStatus::Confirmed) {
                    if ($model instanceof Order) {
                        $model->update([
                            'shipping_city_id' => $validated['shipping_city_id'] ?? $model->shipping_city_id,
                            'confirmed_shipping_address' => $validated['shipping_address'] ?? $model->confirmed_shipping_address
                                ?? data_get($model->platform_data, 'shipping.address_1'),
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
                }

                $workflow->transition($model->refresh(), $target, $user, $validated['reason'] ?? null);
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

        return Inertia::render('Dashboard/Orders/Show', [
            'order'      => $order,
            'store'      => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'invoice'    => $order->invoice ? $order->invoice->only(['id', 'invoice_number', 'status']) : null,
            'canInvoice' => Gate::allows('invoices.issue'),
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

        $order->load('invoice');

        return Inertia::render('Dashboard/Orders/ShowOnline', [
            'order'      => OrderPresenter::online($order) + ['notes' => $order->notes],
            'store'      => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'invoice'    => $order->invoice ? $order->invoice->only(['id', 'invoice_number', 'status']) : null,
            'canInvoice' => Gate::allows('invoices.issue'),
        ]);
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
