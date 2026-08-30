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
     */
    public function create(Organization $organization, User $createdBy, array $data, array $orderIds): FinanceCodSettlement
    {
        $orders = $this->orderTransactions->resolveCollectableOrders($organization, $orderIds);

        $grossAmount = (float) $orders->sum('total');

        $settlement = FinanceCodSettlement::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'carrier_name' => $data['carrier_name'] ?? null,
            'settlement_date' => $data['settlement_date'],
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'gross_cod_amount' => $grossAmount,
            'delivery_fees' => $data['delivery_fees'] ?? 0,
            'adjustments' => $data['adjustments'] ?? 0,
            'net_received' => $grossAmount - (float) ($data['delivery_fees'] ?? 0) - (float) ($data['adjustments'] ?? 0),
            'account_id' => $data['account_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => FinanceCodSettlementStatus::Draft,
            'created_by' => $createdBy->id,
        ]);

        $settlement->items()->createMany(
            $orders->map(fn (Order $order) => ['order_id' => $order->id, 'amount' => $order->total])->all()
        );

        return $settlement;
    }

    /**
     * Finalize a draft settlement: close every included order's receivable
     * (Neutral, one per order — see FinanceOrderTransactionService::closeCodReceivable())
     * and book ONE cash-in entry for the net amount actually received.
     * Idempotent — calling this again for an already-settled record is a
     * no-op (the unique ledger index guarantees the aggregate entry is
     * never duplicated, and closed orders are simply skipped).
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
            $occurredAt = CarbonImmutable::parse($settlement->settlement_date);

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

            $net = (float) $settlement->net_received;

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

            $settlement->update(['status' => FinanceCodSettlementStatus::Settled, 'settled_at' => now()]);
        });

        return $settlement->refresh();
    }

    public function cancel(FinanceCodSettlement $settlement): FinanceCodSettlement
    {
        if ($settlement->isDraft()) {
            $settlement->update(['status' => FinanceCodSettlementStatus::Cancelled]);
        }

        return $settlement->refresh();
    }
}
