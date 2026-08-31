<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceCodSettlementStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceCodSettlement;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * External carrier COD settlement — a delivery company (Ozon, Sendit, or
 * any third-party) periodically remits the NET cash it collected on our
 * behalf (gross COD minus its delivery fees and adjustments) for a batch of
 * orders it already delivered. Two-step, mirroring FinanceExpenseService's
 * create-then-mark-paid shape: create() saves a draft with the selected
 * orders attached (nothing posted to the ledger yet); settle() finalizes it
 * — closing every included order's receivable and booking exactly one cash
 * entry for the net amount actually received. Never double-counts cash:
 * the gross-per-order entries are Neutral facts, only the aggregate net
 * entry moves an account balance.
 */
class FinanceCodSettlementService
{
    public function __construct(
        private readonly FinanceOrderTransactionService $orderTransactions,
        private readonly FinanceTransactionService $transactions,
    ) {}

    /**
     * @param  array<int, string>  $orderIds
     *
     * `delivery_fees`, when omitted entirely (not merely 0 — see the
     * `array_key_exists` check below), is auto-computed from each selected
     * order's OWN Shipment fee snapshot (a manual override always wins over
     * the computed snapshot — Shipment::effectiveCarrierFee()). This is what
     * makes a provider-period settlement (see verifyProviderPeriod()) never
     * need a typed-in fee guess; the legacy manual flow, which always sends
     * `delivery_fees` explicitly (even "0"), is completely unaffected.
     */
    public function create(Organization $organization, User $createdBy, array $data, array $orderIds): FinanceCodSettlement
    {
        $orders = $this->orderTransactions->resolveCollectableOrders($organization, $orderIds);

        $grossAmount = (float) $orders->sum('total');

        $deliveryFees = array_key_exists('delivery_fees', $data) && $data['delivery_fees'] !== null
            ? (float) $data['delivery_fees']
            : (float) $orders->sum(fn (Order $order) => $order->shipment?->effectiveCarrierFee() ?? 0.0);

        $adjustments = (float) ($data['adjustments'] ?? 0);
        $netReceived = $grossAmount - $deliveryFees - $adjustments;

        $settlement = FinanceCodSettlement::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'carrier_name' => $data['carrier_name'] ?? null,
            'delivery_provider_id' => $data['delivery_provider_id'] ?? null,
            'settlement_date' => $data['settlement_date'],
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'gross_cod_amount' => $grossAmount,
            'delivery_fees' => $deliveryFees,
            'adjustments' => $adjustments,
            'net_received' => $netReceived,
            // Immutable snapshot of "what we expected" — settle() later
            // compares an accountant-verified actual amount against THIS,
            // never against net_received (which settle() itself updates).
            'expected_net_amount' => $netReceived,
            'account_id' => $data['account_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => FinanceCodSettlementStatus::Draft,
            'created_by' => $createdBy->id,
        ]);

        $settlement->items()->createMany(
            $orders->map(fn (Order $order) => [
                'order_id' => $order->id,
                'amount' => $order->total,
                'expected_fee' => $order->shipment?->effectiveCarrierFee(),
                'fee_source' => $order->shipment?->fee_source,
            ])->all()
        );

        return $settlement;
    }

    /**
     * Finalize a draft settlement: close every included order's receivable
     * (Neutral, one per order — see FinanceOrderTransactionService::closeCodReceivable())
     * and book ONE cash-in entry. Idempotent — calling this again for an
     * already-settled/disputed/partial record is a no-op (isDraft() is
     * false by then).
     *
     * `actual_received_amount`, when set (via reconcile()/
     * verifyProviderPeriod() — never by the plain legacy "Settle" button),
     * becomes the cash-in amount instead of the create()-time `net_received`
     * estimate, and is compared against the immutable `expected_net_amount`
     * snapshot to resolve variance/status:
     *   - within a cent of expected -> Settled (the normal, no-surprise case)
     *   - received LESS than expected -> Partial
     *   - received MORE than expected -> Disputed (unusual, needs a look)
     * A legacy settlement (actual_received_amount never set) always
     * resolves to Settled exactly as before this feature existed.
     */
    public function settle(FinanceCodSettlement $settlement): FinanceCodSettlement
    {
        if (! $settlement->isDraft()) {
            return $settlement;
        }

        if ($settlement->account_id === null) {
            throw ValidationException::withMessages(['account_id' => 'Select the account this settlement was received into before settling it.']);
        }

        // Atomic: if ANY included order fails its delivery re-check inside
        // closeCodReceivable() (its status could have changed since the
        // draft was created), the whole settlement rolls back — no
        // partially-applied closing facts, no aggregate cash entry either.
        DB::transaction(function () use ($settlement) {
            // A settlement dated TODAY (the normal "Verify bank transfer"
            // case — no genuine backdating) should record at the real
            // moment it was verified, not midnight, so it sorts correctly
            // against same-day sale/receivable transactions in the
            // Transactions list (occurred_at DESC). A deliberately backdated
            // settlement_date (accountant entering a past date) has no real
            // time component to use, so it keeps resolving to that date's
            // midnight — unchanged, existing behavior.
            $settlementDate = CarbonImmutable::parse($settlement->settlement_date);
            $occurredAt = $settlementDate->isSameDay(CarbonImmutable::now()) ? CarbonImmutable::now() : $settlementDate;

            // See create()'s note on withoutTenancy() — an item's order may
            // belong to a sibling store that isn't the "active" one right now.
            Order::withoutTenancy(fn () => $settlement->load('items.order'));
            foreach ($settlement->items as $item) {
                if ($item->order === null) {
                    continue;
                }

                $this->orderTransactions->closeCodReceivable(
                    $item->order,
                    FinanceTransactionType::CodSettledExternal,
                    $occurredAt,
                    ['settlement_id' => $settlement->id],
                );
            }

            $hasActual = $settlement->actual_received_amount !== null;
            $net = $hasActual ? (float) $settlement->actual_received_amount : (float) $settlement->net_received;
            $expected = $settlement->expected_net_amount !== null ? (float) $settlement->expected_net_amount : (float) $settlement->net_received;
            $variance = $hasActual ? round($net - $expected, 2) : null;

            $this->transactions->record([
                'organization_id' => $settlement->organization_id,
                'store_id' => $settlement->store_id,
                'account_id' => $settlement->account_id,
                'direction' => $net >= 0 ? FinanceTransactionDirection::In : FinanceTransactionDirection::Out,
                'type' => FinanceTransactionType::CodSettlementReceived,
                'amount' => abs($net),
                'occurred_at' => $occurredAt,
                'source_type' => FinanceCodSettlement::class,
                'source_id' => $settlement->id,
                'reference' => $settlement->reference,
                'description' => sprintf(
                    'External COD settlement received%s — gross %s, fees %s, net %s',
                    $settlement->carrier_name ? " ({$settlement->carrier_name})" : '',
                    number_format((float) $settlement->gross_cod_amount, 2),
                    number_format((float) $settlement->delivery_fees, 2),
                    number_format($net, 2),
                ),
                'metadata' => [
                    'gross_cod_amount' => (float) $settlement->gross_cod_amount,
                    'delivery_fees' => (float) $settlement->delivery_fees,
                    'adjustments' => (float) $settlement->adjustments,
                    'net_received' => $net,
                ],
            ]);

            // Informational only — the fee the cash-in amount above already
            // implicitly nets out. Never moves cash on its own; purely so
            // Monthly Statement / reporting can show "carrier fees this
            // period" without re-deriving it from settlement rows.
            if ((float) $settlement->delivery_fees > 0.0) {
                $this->transactions->record([
                    'organization_id' => $settlement->organization_id,
                    'store_id' => $settlement->store_id,
                    'direction' => FinanceTransactionDirection::Neutral,
                    'type' => FinanceTransactionType::CodSettlementFeeIncurred,
                    'amount' => (float) $settlement->delivery_fees,
                    'occurred_at' => $occurredAt,
                    'source_type' => FinanceCodSettlement::class,
                    'source_id' => $settlement->id,
                    'description' => sprintf(
                        'Carrier delivery fees incurred%s — %s',
                        $settlement->carrier_name ? " ({$settlement->carrier_name})" : '',
                        number_format((float) $settlement->delivery_fees, 2),
                    ),
                ]);
            }

            $status = FinanceCodSettlementStatus::Settled;

            if ($hasActual && $variance !== null && abs($variance) > 0.001) {
                $status = $net < $expected ? FinanceCodSettlementStatus::Partial : FinanceCodSettlementStatus::Disputed;

                $this->transactions->record([
                    'organization_id' => $settlement->organization_id,
                    'store_id' => $settlement->store_id,
                    'direction' => FinanceTransactionDirection::Neutral,
                    'type' => FinanceTransactionType::CodSettlementVariance,
                    'amount' => abs($variance),
                    'occurred_at' => $occurredAt,
                    'source_type' => FinanceCodSettlement::class,
                    'source_id' => $settlement->id,
                    'description' => $variance < 0
                        ? 'Received ' . number_format(abs($variance), 2) . ' less than expected'
                        : 'Received ' . number_format($variance, 2) . ' more than expected',
                    'metadata' => ['expected_net_amount' => $expected, 'actual_received_amount' => $net],
                ]);
            }

            $settlement->update([
                'net_received' => $net,
                'variance_amount' => $variance,
                'status' => $status,
                'settled_at' => now(),
            ]);
        });

        return $settlement->refresh();
    }

    /**
     * Attach an accountant's bank-transfer verification to an existing
     * draft (legacy manual OR provider-period) and finalize it in one call.
     * A no-op if the settlement is no longer a draft — repeating this after
     * it already settled/disputed/partial changes nothing.
     *
     * @param  array{actual_received_amount: float, account_id?: ?string, received_at?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function reconcile(FinanceCodSettlement $settlement, array $data): FinanceCodSettlement
    {
        if (! $settlement->isDraft()) {
            return $settlement;
        }

        $settlement->update([
            'actual_received_amount' => $data['actual_received_amount'],
            'account_id' => $data['account_id'] ?? $settlement->account_id,
            'received_at' => $data['received_at'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? $settlement->reference,
            'dispute_note' => $data['notes'] ?? null,
        ]);

        return $this->settle($settlement->refresh());
    }

    /**
     * The provider-period "Verify bank transfer" action from the External
     * settlements tab — creates the draft (auto-filling expected fees from
     * each order's Shipment snapshot, see create()) and reconciles it with
     * the accountant's actual amount, in one atomic step. Orders come from
     * FinanceCodPayoutPeriodService::pendingPeriods() — never trusted blind:
     * create() re-validates every id through the same
     * resolveCollectableOrders() gate as the manual flow.
     *
     * @param  array<int, string>  $orderIds
     * @param  array{delivery_provider_id: string, actual_received_amount: float, account_id: string, period_start?: ?string, period_end?: ?string, received_at?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function verifyProviderPeriod(Organization $organization, User $createdBy, array $data, array $orderIds): FinanceCodSettlement
    {
        return DB::transaction(function () use ($organization, $createdBy, $data, $orderIds) {
            $settlement = $this->create($organization, $createdBy, [
                'delivery_provider_id' => $data['delivery_provider_id'],
                'settlement_date' => $data['received_at'] ?? now()->toDateString(),
                'period_start' => $data['period_start'] ?? null,
                'period_end' => $data['period_end'] ?? null,
                'account_id' => $data['account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                // delivery_fees deliberately omitted — create() auto-sums
                // each order's Shipment::effectiveCarrierFee() snapshot.
            ], $orderIds);

            return $this->reconcile($settlement, $data);
        });
    }

    public function cancel(FinanceCodSettlement $settlement): FinanceCodSettlement
    {
        if ($settlement->isDraft()) {
            $settlement->update(['status' => FinanceCodSettlementStatus::Cancelled]);
        }

        return $settlement->refresh();
    }
}
