<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceCodSettlementStatus;
use App\Enums\FinanceCourierDepositStatus;
use App\Enums\FinanceExpenseStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceCodSettlement;
use App\Models\FinanceCourierDeposit;
use App\Models\FinanceExpense;
use App\Models\FinanceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the monthly finance statement.
 *
 * Phase 1 sections (expenses) are unchanged in shape — see `totals`,
 * `by_category`, `by_payment_method`, `by_vendor`, `upcoming_unpaid_due`,
 * `export_rows` below.
 *
 * Phase 2 adds a `cashflow` section from the finance_transactions ledger.
 * CRITICAL: sales_created and collections are counted by the ledger
 * transaction's OWN occurred_at, which for a sale is the order's real
 * creation date and for a collection is the actual payment/COD-collection
 * date — never "now". A sale created in August but only collected in
 * September will show its sale in August's statement and its collection in
 * September's, exactly like a real cash-basis ledger, never mixed together.
 */
class FinanceMonthlyStatementService
{
    public function forMonth(string $month, ?string $storeId = null): array
    {
        $monthStart = CarbonImmutable::parse($month . '-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        // whereDate (not whereBetween/where) throughout this method — a `date`-cast
        // column is stored as a full 'Y-m-d 00:00:00' string on drivers without a
        // real DATE type (SQLite), so a plain string comparison against a bare
        // 'Y-m-d' value wrongly excludes same-day rows (longer string sorts higher).
        $base = fn (): Builder => FinanceExpense::query()
            ->with(['category:id,name', 'vendor:id,name', 'store:id,name'])
            ->whereDate('expense_date', '>=', $monthStart->toDateString())
            ->whereDate('expense_date', '<=', $monthEnd->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));

        $all = $base()->get();
        $paid = $all->where('status', FinanceExpenseStatus::Paid);
        $unpaid = $all->where('status', FinanceExpenseStatus::Unpaid);
        $cancelled = $all->where('status', FinanceExpenseStatus::Cancelled);
        $recurring = $all->whereNotNull('recurring_expense_id');

        $upcomingUnpaidDue = FinanceExpense::query()
            ->where('status', FinanceExpenseStatus::Unpaid)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', CarbonImmutable::now()->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId))
            ->with(['category:id,name', 'vendor:id,name'])
            ->orderBy('due_date')
            ->limit(20)
            ->get();

        $txBase = fn (): Builder => FinanceTransaction::query()
            ->whereDate('occurred_at', '>=', $monthStart->toDateString())
            ->whereDate('occurred_at', '<=', $monthEnd->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));

        $transactions = $txBase()->with(['account:id,name,type', 'store:id,name'])->get();

        $salesCreated = $transactions->where('type', FinanceTransactionType::SaleCreated);
        // CodSettlementReceived is the NET bank amount an external carrier
        // actually remitted — it belongs in "money collected" alongside
        // direct payments and courier cash deposits (both booked as
        // CodCollected). The per-order CodSettledExternal/CodClearedByCourier
        // closing facts are deliberately excluded (Neutral, no cash moved).
        $collections = $transactions->whereIn('type', [
            FinanceTransactionType::PaymentCollected, FinanceTransactionType::CodCollected, FinanceTransactionType::CodSettlementReceived,
        ]);
        $refunds = $transactions->whereIn('type', [FinanceTransactionType::RefundPaid, FinanceTransactionType::ReturnAdjustment]);
        $cashIn = (float) $transactions->where('direction', FinanceTransactionDirection::In)->sum('amount');
        $cashOut = (float) $transactions->where('direction', FinanceTransactionDirection::Out)->sum('amount');

        // Pending receivables is a snapshot AT MONTH END, not a flow within
        // the month — everything created up to and including the last day of
        // the month, minus everything CLOSED by then. "Closed" means any of
        // the three ways a receivable stops being pending — see
        // FinanceTransactionType::codClosingTypes() — not just the ad-hoc
        // single-order "mark collected" action.
        $codSnapshot = fn (array $types) => (float) FinanceTransaction::query()
            ->whereIn('type', $types)
            ->whereDate('occurred_at', '<=', $monthEnd->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId))
            ->sum('amount');
        $pendingReceivablesAtMonthEnd = max(0.0, $codSnapshot([FinanceTransactionType::CodReceivableCreated->value]) - $codSnapshot(FinanceTransactionType::codClosingTypes()));

        // COD closed THIS MONTH, split by which workflow closed it — for the
        // statement's "how did pending COD move this month" section. Read
        // straight from the settlement/deposit records (rather than
        // re-deriving from the ledger) so gross/fees/net stay exactly as
        // the accountant entered them.
        $codSettlementsBase = fn (): Builder => FinanceCodSettlement::query()
            ->where('status', FinanceCodSettlementStatus::Settled->value)
            ->whereDate('settlement_date', '>=', $monthStart->toDateString())
            ->whereDate('settlement_date', '<=', $monthEnd->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
        $codSettlementsThisMonth = $codSettlementsBase()->get();

        $codDepositsBase = fn (): Builder => FinanceCourierDeposit::query()
            ->where('status', FinanceCourierDepositStatus::Confirmed->value)
            ->whereDate('deposit_date', '>=', $monthStart->toDateString())
            ->whereDate('deposit_date', '<=', $monthEnd->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
        $codDepositsThisMonth = $codDepositsBase()->get();

        // Expenses actually PAID this month — read from the LEDGER
        // (expense_paid transactions), not the finance_expenses table.
        // Cashflow/net-cash-movement must only ever reflect
        // finance_transactions: the ledger row is written once (append-only)
        // with occurred_at = the paid_at/expense_date that was true AT THE
        // MOMENT it was paid, so it stays correct even if the expense is
        // later edited, cancelled, or its status otherwise changes — unlike
        // a live query against finance_expenses.status, which would make a
        // cancelled expense silently vanish from "money paid out" even
        // though the cash truly left (and would just as silently double back
        // to a stale value if the same expense were re-paid at a different
        // amount). This is distinct from `totals.paid_expenses` below
        // (Phase 1, unchanged), which is an EXPENSE-management total scoped
        // by expense_date regardless of when/whether it was paid.
        $expensesPaidTx = $transactions->where('type', FinanceTransactionType::ExpensePaid);

        $byAccount = $transactions
            ->groupBy(fn (FinanceTransaction $t) => $t->account_id ?? '__unassigned')
            ->map(fn ($group) => [
                'account_id' => $group->first()->account_id,
                'account_name' => $group->first()->account?->name ?? 'Unassigned',
                'count' => $group->count(),
                'in' => (float) $group->where('direction', FinanceTransactionDirection::In)->sum('amount'),
                'out' => (float) $group->where('direction', FinanceTransactionDirection::Out)->sum('amount'),
            ])
            ->sortByDesc(fn ($row) => $row['in'])
            ->values();

        $byStoreCashflow = $transactions
            ->whereNotNull('store_id')
            ->groupBy('store_id')
            ->map(fn ($group) => [
                'store_id' => $group->first()->store_id,
                'store_name' => $group->first()->store?->name,
                'sales_created' => (float) $group->where('type', FinanceTransactionType::SaleCreated)->sum('amount'),
                'collected' => (float) $group->whereIn('type', [FinanceTransactionType::PaymentCollected, FinanceTransactionType::CodCollected, FinanceTransactionType::CodSettlementReceived])->sum('amount'),
            ])
            ->sortByDesc('sales_created')
            ->values();

        return [
            'month' => $monthStart->format('Y-m'),
            'store_id' => $storeId,
            'cashflow' => [
                'sales_created' => ['count' => $salesCreated->count(), 'amount' => (float) $salesCreated->sum('amount')],
                'collections' => ['count' => $collections->count(), 'amount' => (float) $collections->sum('amount')],
                'pending_receivables_at_month_end' => $pendingReceivablesAtMonthEnd,
                'expenses_paid' => ['count' => $expensesPaidTx->count(), 'amount' => (float) $expensesPaidTx->sum('amount')],
                'refunds' => ['count' => $refunds->count(), 'amount' => (float) $refunds->sum('amount')],
                'net_cash_movement' => $cashIn - $cashOut,
                'by_account' => $byAccount,
                'by_store' => $byStoreCashflow,
                'recent_transactions' => $transactions->sortByDesc('occurred_at')->take(20)->values(),
            ],
            // COD, broken out by workflow so pending / collected / fees /
            // net never get mixed into one number — see the class docblock.
            'cod' => [
                'pending_at_month_end' => $pendingReceivablesAtMonthEnd,
                'collected_via_external_settlement' => [
                    'count' => $codSettlementsThisMonth->count(),
                    'gross' => (float) $codSettlementsThisMonth->sum('gross_cod_amount'),
                    'delivery_fees' => (float) $codSettlementsThisMonth->sum('delivery_fees'),
                    'adjustments' => (float) $codSettlementsThisMonth->sum('adjustments'),
                    'net_received' => (float) $codSettlementsThisMonth->sum('net_received'),
                ],
                'collected_via_courier_deposit' => [
                    'count' => $codDepositsThisMonth->count(),
                    'expected' => (float) $codDepositsThisMonth->sum('expected_amount'),
                    'cash_received' => (float) $codDepositsThisMonth->sum('cash_received'),
                    'difference' => (float) $codDepositsThisMonth->sum('difference'),
                ],
            ],
            'totals' => [
                // Cancelled expenses are excluded from the ACTIVE total —
                // they're still visible via `cancelled_expenses` below (an
                // audit/history line), they just don't inflate the figure
                // that reads as "what this month cost".
                'total_expenses' => ['count' => $paid->count() + $unpaid->count(), 'amount' => (float) ($paid->sum('amount') + $unpaid->sum('amount'))],
                'paid_expenses' => ['count' => $paid->count(), 'amount' => (float) $paid->sum('amount')],
                'unpaid_expenses' => ['count' => $unpaid->count(), 'amount' => (float) $unpaid->sum('amount')],
                'cancelled_expenses' => ['count' => $cancelled->count(), 'amount' => (float) $cancelled->sum('amount')],
                'recurring_generated' => ['count' => $recurring->count(), 'amount' => (float) $recurring->sum('amount')],
            ],
            'by_category' => $all->reject(fn (FinanceExpense $e) => $e->status === FinanceExpenseStatus::Cancelled)
                ->groupBy('category_id')
                ->map(fn ($group) => [
                    'category_id' => $group->first()->category_id,
                    'category_name' => $group->first()->category?->name,
                    'count' => $group->count(),
                    'amount' => (float) $group->sum('amount'),
                ])
                ->sortByDesc('amount')
                ->values(),
            'by_payment_method' => $paid->groupBy(fn (FinanceExpense $e) => $e->payment_method?->value ?? 'unspecified')
                ->map(fn ($group, $method) => [
                    'payment_method' => $method,
                    'count' => $group->count(),
                    'amount' => (float) $group->sum('amount'),
                ])
                ->sortByDesc('amount')
                ->values(),
            'by_vendor' => $all->reject(fn (FinanceExpense $e) => $e->status === FinanceExpenseStatus::Cancelled)
                ->whereNotNull('vendor_id')
                ->groupBy('vendor_id')
                ->map(fn ($group) => [
                    'vendor_id' => $group->first()->vendor_id,
                    'vendor_name' => $group->first()->vendor?->name,
                    'count' => $group->count(),
                    'amount' => (float) $group->sum('amount'),
                ])
                ->sortByDesc('amount')
                ->values(),
            'upcoming_unpaid_due' => $upcomingUnpaidDue,
            'export_rows' => $all->map(fn (FinanceExpense $e) => [
                'date' => $e->expense_date->toDateString(),
                'title' => $e->title,
                'category' => $e->category?->name,
                'vendor' => $e->vendor?->name,
                'store' => $e->store?->name,
                'amount' => (float) $e->amount,
                'currency' => $e->currency,
                'status' => $e->status->value,
                'payment_method' => $e->payment_method?->value,
                'reference' => $e->reference,
            ])->values(),
        ];
    }
}
