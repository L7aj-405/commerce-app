<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceCodCollectRequest;
use App\Models\DeliveryProvider;
use App\Models\FinanceAccount;
use App\Models\FinanceCodSettlement;
use App\Models\FinanceCourierDeposit;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceCodCollectabilityService;
use App\Services\Finance\FinanceCodPayoutPeriodService;
use App\Services\Finance\FinanceCodSettlementDiagnosticsService;
use App\Services\Finance\FinanceDeliveryProviderFeeCalculator;
use App\Services\Finance\FinanceOrderTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceCodReceivableController extends Controller
{
    public function index(
        Request $request,
        FinanceOrderTransactionService $finance,
        FinanceCodCollectabilityService $collectability,
        FinanceCodPayoutPeriodService $periods,
        FinanceCodSettlementDiagnosticsService $diagnostics,
    ): Response {
        $store = $request->user()->getActiveStore();
        $organization = $store?->organization;

        $orders = collect();
        $settlements = collect();
        $deposits = collect();
        $couriers = collect();
        $providerPeriods = collect();

        if ($organization !== null) {
            $pendingIds = $finance->pendingCodOrderIds($organization->id);

            // Live payout periods for every configured provider — computed
            // BEFORE the order list below so each row can say which period
            // (if any) it already belongs to.
            $providerPeriods = $periods->pendingPeriods($organization);

            // Which period (if any) each order already belongs to — a
            // lookup by order id so the "View settlement period" action
            // can jump straight to the right card instead of the accountant
            // having to hunt for it. Never trust this to be exhaustive: an
            // order that's genuinely eligible but not yet grouped (missing
            // Shipment data, no fee snapshot, etc.) simply won't be in here,
            // which is exactly what the diagnostics below are for.
            $periodByOrderId = $providerPeriods->flatMap(
                fn (array $period) => collect($period['order_ids'])->mapWithKeys(fn (string $orderId) => [$orderId => $period])
            );

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
                // store:...,organization_id — FinanceCodSettlementDiagnosticsService
                // needs $order->store->organization to look up the
                // provider's payout settings; a restricted column list
                // without the FK would silently resolve that relation to
                // null instead of triggering a query.
                ->with(['store:id,name,organization_id', 'shipment.provider:code,name', 'orderShipment.agent:id,name'])
                ->orderBy('created_at')
                ->get())
                ->map(function (Order $order) use ($collectability, $diagnostics, $periodByOrderId) {
                    $assessment = $collectability->assess($order);

                    // Only worth computing for a row that's actually
                    // claiming to be "awaiting provider payout" — this is
                    // what makes "View settlement period" never lead to a
                    // silently empty tab: either it's already in a live
                    // period (settlement_period below), or we can say
                    // exactly why it isn't yet.
                    $settlementPeriod = null;
                    $settlementDiagnostics = null;

                    if ($assessment['collectability_status'] === 'delivered_awaiting_provider_payout') {
                        $match = $periodByOrderId->get($order->id);

                        if ($match !== null) {
                            $settlementPeriod = [
                                'delivery_provider_id' => $match['delivery_provider_id'],
                                'provider_code' => $match['provider_code'],
                                'period_start' => $match['period_start'],
                            ];
                        } else {
                            $settlementDiagnostics = $diagnostics->diagnose($order)['reasons'];
                        }
                    }

                    return [
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
                        ...$assessment,
                        'settlement_period' => $settlementPeriod,
                        'settlement_diagnostics' => $settlementDiagnostics,
                    ];
                });

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
            'providerPeriods' => $providerPeriods->values(),
            'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
            'couriers' => $couriers->values(),
            'can' => [
                'manage_settlements' => $request->user()->hasStorePermission($store, 'finance.manage_cod_settlements'),
                // Dev/local-only diagnostic tool — see recalculateSettlement().
                'recalculate_settlement' => app()->environment(['local', 'testing']) && $request->user()->isPrivilegedFor($store),
            ],
        ]);
    }

    /**
     * LOCAL/TESTING ONLY — never reachable in production (see the guard
     * below, and FinanceCodReceivableTest for the regression test). A
     * diagnostic/data-repair tool, not a Finance action: it only ever
     * touches the Shipment row (backfilling a stale/missing delivered_at,
     * or — when no Shipment exists at all but the dispatch board's own
     * order_shipments record names a known provider — creating a minimal
     * one from that) and (re)computes the fee snapshot. It NEVER creates a
     * FinanceTransaction and NEVER closes the receivable; the only reason
     * an order becomes settle-able afterward is that
     * FinanceCodPayoutPeriodService can now find real Shipment/fee data for
     * it, exactly like a real Ozon/Sendit delivery webhook would have
     * already provided.
     */
    public function recalculateSettlement(Request $request, Order $order, FinanceDeliveryProviderFeeCalculator $calculator): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless($request->user()->isPrivilegedFor($request->user()->getActiveStore()), 403);

        if (! in_array($order->fulfillment_status, [FulfillmentStatus::Delivered, FulfillmentStatus::Completed], true)) {
            throw ValidationException::withMessages(['order' => 'Order is not delivered yet — mark the order delivered before recalculating.']);
        }

        $shipment = $order->shipment ?? $this->createShipmentFromOrderShipment($order);

        // Same shared step DispatchService::markDelivered() and
        // ShipmentTrackingService::apply() use — this tool's only remaining
        // special case is createShipmentFromOrderShipment() above, for when
        // no Shipment exists at all yet.
        $calculator->prepareShipmentForSettlement($shipment);

        return back()->with('success', 'Settlement data recalculated — check External Settlements.');
    }

    /**
     * Only when NO real Shipment exists at all, but the dispatch board's own
     * order_shipments row names an external courier whose free-text name
     * matches a known DeliveryProvider — the exact "I typed Ozon Express on
     * the Delivery Board instead of sending it through the Ozon
     * integration" scenario this tool exists to unblock in local testing.
     * A courier name that matches nothing known is left alone (never a
     * guess) — the diagnostics correctly keep reporting "no external
     * shipment found".
     */
    private function createShipmentFromOrderShipment(Order $order): Shipment
    {
        $orderShipment = $order->orderShipment;
        $provider = ($orderShipment !== null && ! $orderShipment->isInternal() && $orderShipment->carrier_name !== null)
            ? $this->matchProviderFromCarrierName($orderShipment->carrier_name)
            : null;

        if ($provider === null) {
            throw ValidationException::withMessages([
                'order' => 'No external shipment found for this order, and its courier name (if any) does not match a known delivery provider — it cannot be prepared for a payout period automatically.',
            ]);
        }

        return Shipment::create([
            'store_id' => $order->store_id,
            'organization_id' => $order->organization_id,
            'shippable_type' => Order::class,
            'shippable_id' => $order->id,
            'provider_code' => $provider->code,
            'status' => Shipment::STATUS_DELIVERED,
            'city_name' => $order->shippingCity?->name,
            // receiver_name/phone/address are NOT NULL on shipments — a
            // bare test/local order may have none of its usual sources set,
            // so this always falls back to something rather than a 500.
            'receiver_name' => $order->customer_name ?? 'N/A',
            'phone' => $order->customer_phone ?? 'N/A',
            'address' => $order->confirmed_shipping_address ?? $orderShipment?->delivery_address ?? 'N/A',
            'cod_amount' => $order->total,
            'sent_at' => $orderShipment?->dispatched_at ?? now(),
            'delivered_at' => $orderShipment?->delivered_at ?? now(),
        ]);
    }

    private function matchProviderFromCarrierName(string $carrierName): ?DeliveryProvider
    {
        $normalized = strtolower(trim($carrierName));

        if ($normalized === '') {
            return null;
        }

        return DeliveryProvider::query()
            ->where('is_active', true)
            ->where('code', '!=', DeliveryProvider::INTERNAL)
            ->get()
            ->first(fn (DeliveryProvider $p) => str_contains($normalized, strtolower($p->code))
                || str_contains(strtolower($p->name), $normalized)
                || str_contains($normalized, strtolower($p->name)));
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
