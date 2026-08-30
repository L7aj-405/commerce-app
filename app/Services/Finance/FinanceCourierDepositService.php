<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceCourierDepositStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceCourierDeposit;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal courier cash deposit — a company employee/livreur who delivered
 * COD orders and collected cash physically hands that cash to the
 * accountant. Two-step, mirroring FinanceCodSettlementService: create()
 * saves a draft with the selected orders and expected amount; confirm()
 * finalizes it — closing every included order's receivable (Neutral, one
 * per order) and booking ONE `cod_collected` entry for the actual cash
 * handed over. Any gap between expected and received cash is recorded as a
 * separate, clearly-labelled Neutral variance entry — it never inflates or
 * deflates the cash entry itself, so cash is never double-counted.
 */
class FinanceCourierDepositService
{
    public function __construct(
        private readonly FinanceOrderTransactionService $orderTransactions,
        private readonly FinanceTransactionService $transactions,
    ) {}

    /**
     * @param  array<int, string>  $orderIds
     */
    public function create(Organization $organization, User $createdBy, array $data, array $orderIds): FinanceCourierDeposit
    {
        $orders = $this->orderTransactions->resolveCollectableOrders($organization, $orderIds);
        $this->assertOrdersMatchCourier($orders, $data['courier_id']);
        $expected = (float) $orders->sum('total');

        $deposit = FinanceCourierDeposit::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'courier_id' => $data['courier_id'],
            'deposit_date' => $data['deposit_date'],
            'expected_amount' => $expected,
            'cash_received' => $data['cash_received'] ?? $expected,
            'difference' => (float) ($data['cash_received'] ?? $expected) - $expected,
            'account_id' => $data['account_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => FinanceCourierDepositStatus::Draft,
            'created_by' => $createdBy->id,
        ]);

        $deposit->items()->createMany(
            $orders->map(fn (Order $order) => ['order_id' => $order->id, 'amount' => $order->total])->all()
        );

        return $deposit;
    }

    /**
     * Finalize a draft deposit: close every included order's receivable,
     * book the actual cash received (one `cod_collected` entry), and — if
     * cash_received differs from expected_amount — a Neutral variance entry
     * naming the shortage/overage. Idempotent, same guarantee as
     * FinanceCodSettlementService::settle().
     */
    public function confirm(FinanceCourierDeposit $deposit): FinanceCourierDeposit
    {
        if (! $deposit->isDraft()) {
            return $deposit;
        }

        if ($deposit->account_id === null) {
            throw ValidationException::withMessages(['account_id' => 'Select the account this cash was deposited into before confirming.']);
        }

        // Atomic: if ANY included order fails its delivery re-check inside
        // closeCodReceivable() (its status could have changed since the
        // draft was created), the whole deposit rolls back — no
        // partially-applied closing facts, no cash entry either.
        DB::transaction(function () use ($deposit) {
            $occurredAt = CarbonImmutable::parse($deposit->deposit_date);

            // See FinanceCodSettlementService's note on withoutTenancy() — an
            // item's order may belong to a sibling store in the same organization.
            Order::withoutTenancy(fn () => $deposit->load('items.order'));
            foreach ($deposit->items as $item) {
                if ($item->order === null) {
                    continue;
                }

                $this->orderTransactions->closeCodReceivable(
                    $item->order,
                    FinanceTransactionType::CodClearedByCourier,
                    $occurredAt,
                    ['deposit_id' => $deposit->id],
                );
            }

            $courierName = $deposit->courier?->name ?? 'courier';

            $this->transactions->record([
                'organization_id' => $deposit->organization_id,
                'store_id' => $deposit->store_id,
                'account_id' => $deposit->account_id,
                'direction' => FinanceTransactionDirection::In,
                'type' => FinanceTransactionType::CodCollected,
                'amount' => (float) $deposit->cash_received,
                'occurred_at' => $occurredAt,
                'source_type' => FinanceCourierDeposit::class,
                'source_id' => $deposit->id,
                'reference' => $deposit->reference,
                'description' => "COD cash deposit from {$courierName} — expected " . number_format((float) $deposit->expected_amount, 2) . ', received ' . number_format((float) $deposit->cash_received, 2),
            ]);

            $difference = (float) $deposit->cash_received - (float) $deposit->expected_amount;

            if (abs($difference) > 0.001) {
                $this->transactions->record([
                    'organization_id' => $deposit->organization_id,
                    'store_id' => $deposit->store_id,
                    'direction' => FinanceTransactionDirection::Neutral,
                    'type' => FinanceTransactionType::CodCourierVariance,
                    'amount' => abs($difference),
                    'occurred_at' => $occurredAt,
                    'source_type' => FinanceCourierDeposit::class,
                    'source_id' => $deposit->id,
                    'reference' => $deposit->reference,
                    'description' => $difference < 0
                        ? "Courier cash shortage of " . number_format(abs($difference), 2) . " from {$courierName}'s deposit"
                        : "Courier cash overage of " . number_format($difference, 2) . " from {$courierName}'s deposit",
                ]);
            }

            $deposit->update(['status' => FinanceCourierDepositStatus::Confirmed, 'confirmed_at' => now()]);
        });

        return $deposit->refresh();
    }

    public function cancel(FinanceCourierDeposit $deposit): FinanceCourierDeposit
    {
        if ($deposit->isDraft()) {
            $deposit->update(['status' => FinanceCourierDepositStatus::Cancelled]);
        }

        return $deposit->refresh();
    }

    /**
     * If an order is already assigned to a SPECIFIC internal courier (the
     * dispatch board's OrderShipment.agent_id), that assignment must match
     * the courier this deposit is being made for — an order handed to one
     * driver cannot be swept into another driver's cash deposit. An order
     * with no recorded internal-courier assignment is left alone (nothing
     * to contradict).
     *
     * @param  Collection<int, Order>  $orders
     */
    private function assertOrdersMatchCourier(Collection $orders, string $courierId): void
    {
        $mismatched = $orders->filter(function (Order $order) use ($courierId) {
            $shipment = $order->orderShipment;

            return $shipment !== null && $shipment->isInternal() && $shipment->agent_id !== null && $shipment->agent_id !== $courierId;
        });

        if ($mismatched->isNotEmpty()) {
            throw ValidationException::withMessages([
                'order_ids' => 'The following orders are assigned to a different internal courier: '
                    . $mismatched->map(fn (Order $order) => $order->order_number)->implode(', '),
            ]);
        }
    }
}
