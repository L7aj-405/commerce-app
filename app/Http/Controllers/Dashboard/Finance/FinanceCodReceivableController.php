<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceCodCollectRequest;
use App\Models\FinanceAccount;
use App\Models\FinanceCodSettlement;
use App\Models\FinanceCourierDeposit;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceCodCollectabilityService;
use App\Services\Finance\FinanceOrderTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class FinanceCodReceivableController extends Controller
{
    public function index(Request $request, FinanceOrderTransactionService $finance, FinanceCodCollectabilityService $collectability): Response
    {
        $store = $request->user()->getActiveStore();
        $organization = $store?->organization;

        $orders = collect();
        $settlements = collect();
        $deposits = collect();
        $couriers = collect();

        if ($organization !== null) {
            $pendingIds = $finance->pendingCodOrderIds($organization->id);

            // Finance is an ORGANIZATION-level desk (multiple stores can
            // share one organization), but Order carries the store-level
            // TenantScope (App\Models\Concerns\BelongsToTenant), which
            // otherwise silently restricts this query to whichever ONE
            // store is currently "active" for the user. withoutTenancy()
            // steps around that so a pending COD order placed against a
            // sibling store in the same organization still shows up here —
            // the organization_id boundary above is what actually keeps
            // this tenant-safe, exactly like FinanceTransaction's own
            // withoutOrganizationTenancy() calls.
            //
            // IMPORTANT: do not restrict columns on `shipment`/`orderShipment` —
            // both are morphOne `latestOfMany()` relations, which self-join the
            // table against an aggregate subquery. An unqualified column list
            // (e.g. `shipment:id,shippable_id,...`) makes `shippable_id`
            // ambiguous between the two sides of that join and throws a 500
            // ("ambiguous column name") the moment a receivable actually
            // exists to eager-load. See FinanceCodReceivableTest.
            $orders = Order::withoutTenancy(fn () => Order::query()
                ->whereIn('id', $pendingIds)
                ->with(['store:id,name', 'shipment.provider:code,name', 'orderShipment.agent:id,name'])
                ->orderBy('created_at')
                ->get())
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'customer_phone' => $order->customer_phone,
                    'total' => (float) $order->total,
                    'currency' => $order->currency,
                    'created_at' => $order->created_at?->toIso8601String(),
                    'fulfillment_status' => $order->fulfillment_status?->value,
                    'store' => $order->store?->only(['id', 'name']),
                    // Delivery-lifecycle gate: a cod_receivable_created
                    // transaction only means cash is EXPECTED (written the
                    // moment the order was confirmed) — collectability adds
                    // the "has it actually been delivered yet" check on top,
                    // and is also the single source of the carrier/courier
                    // grouping fields (an order is carried by AT MOST one of
                    // external carrier / external courier / internal agent).
                    ...$collectability->assess($order),
                ]);

            $settlements = FinanceCodSettlement::query()
                ->with(['store:id,name', 'account:id,name'])
                ->orderByDesc('settlement_date')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            $deposits = FinanceCourierDeposit::query()
                ->with(['store:id,name', 'account:id,name', 'courier:id,name'])
                ->orderByDesc('deposit_date')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

            $couriers = $store ? $this->couriers($store) : collect();
        }

        return Inertia::render('Dashboard/Finance/CodReceivables/Index', [
            'orders' => $orders->values(),
            'settlements' => $settlements->values(),
            'deposits' => $deposits->values(),
            'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
            'couriers' => $couriers->values(),
            'can' => [
                'manage_settlements' => $request->user()->hasStorePermission($store, 'finance.manage_cod_settlements'),
            ],
        ]);
    }

    public function markCollected(FinanceCodCollectRequest $request, Order $order, FinanceOrderTransactionService $finance): RedirectResponse
    {
        $validated = $request->validated();

        $account = FinanceAccount::query()->findOrFail($validated['account_id']);

        $finance->markCodCollected(
            order: $order,
            account: $account,
            actor: $request->user(),
            amount: (float) $validated['amount_collected'],
            collectedAt: CarbonImmutable::parse($validated['collected_at']),
            reference: $validated['reference'] ?? null,
            note: $validated['note'] ?? null,
        );

        return back()->with('success', 'COD payment marked as collected.');
    }

    /** Internal delivery agents (permission `orders.deliver`) available to the active store — the courier deposit picklist. */
    private function couriers(Store $store): Collection
    {
        return $store->members()
            ->with('user:id,name')
            ->where('is_active', true)
            ->get()
            ->pluck('user')
            ->filter()
            ->push($store->owner)
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => $u->hasStorePermission($store, 'orders.deliver'))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }
}
