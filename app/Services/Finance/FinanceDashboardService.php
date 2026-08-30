<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceExpenseStatus;
use App\Enums\FinanceRecurringStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceExpense;
use App\Models\FinanceRecurringExpense;
use App\Models\FinanceTransaction;
use Carbon\CarbonImmutable;

/**
 * Read-only metrics for the Finance Dashboard.
 *
 * Phase 1 cards (expenses) are derived straight from finance_expenses /
 * finance_recurring_expenses, unchanged. Phase 2 adds cashflow cards derived
 * from the finance_transactions ledger — sales created, cash collected,
 * pending COD receivables, refunds, and net cash movement. These are
 * deliberately kept as SEPARATE numbers (never summed into one "revenue"),
 * matching the ledger's own distinction between a sale being recorded
 * (neutral) and cash actually moving (in/out) — see FinanceOrderTransactionService.
 *
 * Still no "profit" card: that requires product cost/COGS, which Phase 2
 * does not implement.
 */
class FinanceDashboardService
{
    public function build(): array
    {
        $now = CarbonImmutable::now();
        $monthStart = $now->startOfMonth();
        $monthEnd = $now->endOfMonth();

        // whereDate (not whereBetween/where) throughout this method — a `date`-cast
        // column is stored as a full 'Y-m-d 00:00:00' string on drivers without a
        // real DATE type (SQLite), so a plain string comparison against a bare
        // 'Y-m-d' value wrongly excludes same-day rows (longer string sorts higher).
        $thisMonth = FinanceExpense::query()
            ->whereDate('expense_date', '>=', $monthStart->toDateString())
            ->whereDate('expense_date', '<=', $monthEnd->toDateString())
            ->where('status', '!=', FinanceExpenseStatus::Cancelled);

        $expensesThisMonth = (clone $thisMonth)->sum('amount');
        $paidThisMonth = (clone $thisMonth)->where('status', FinanceExpenseStatus::Paid)->sum('amount');
        $unpaidThisMonth = (clone $thisMonth)->where('status', FinanceExpenseStatus::Unpaid)->sum('amount');

        $unpaidTotal = FinanceExpense::query()->where('status', FinanceExpenseStatus::Unpaid);
        $overdueTotal = (clone $unpaidTotal)->whereNotNull('due_date')->whereDate('due_date', '<', $now->toDateString());

        $topCategory = (clone $thisMonth)
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->with('category:id,name,color,icon')
            ->first();

        // A paid expense with no payment_method (e.g. the list's quick "mark
        // paid" action, which doesn't collect one) must still show up here —
        // grouped as "unspecified" — rather than silently vanish from the
        // total, which previously made "0 paid expenses" look inaccurate.
        // ->toBase() skips Eloquent's enum cast on `payment_method`, which
        // would otherwise throw on the literal 'unspecified' string below.
        $cashOutByMethod = (clone $thisMonth)
            ->where('status', FinanceExpenseStatus::Paid)
            ->selectRaw("COALESCE(payment_method, 'unspecified') as payment_method, SUM(amount) as total, COUNT(*) as count")
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        $upcomingRecurring = FinanceRecurringExpense::query()
            ->where('status', FinanceRecurringStatus::Active)
            ->whereDate('next_due_at', '<=', $now->addDays(30)->toDateString())
            ->with(['category:id,name,color,icon', 'vendor:id,name'])
            ->orderBy('next_due_at')
            ->limit(10)
            ->get();

        $recentExpenses = FinanceExpense::query()
            ->with(['category:id,name,color,icon', 'vendor:id,name'])
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // --- Cashflow (Phase 2) --------------------------------------------
        $txThisMonth = fn () => FinanceTransaction::query()
            ->whereDate('occurred_at', '>=', $monthStart->toDateString())
            ->whereDate('occurred_at', '<=', $monthEnd->toDateString());

        $sumType = fn (FinanceTransactionType $type) => (float) $txThisMonth()->where('type', $type->value)->sum('amount');

        $salesCreated = $sumType(FinanceTransactionType::SaleCreated);
        $moneyCollected = (float) $txThisMonth()
            ->whereIn('type', [
                FinanceTransactionType::PaymentCollected->value,
                FinanceTransactionType::CodCollected->value,
                FinanceTransactionType::CodSettlementReceived->value,
            ])
            ->sum('amount');
        $refundsThisMonth = (float) $txThisMonth()
            ->whereIn('type', [FinanceTransactionType::RefundPaid->value, FinanceTransactionType::ReturnAdjustment->value])
            ->sum('amount');

        $cashIn = (float) $txThisMonth()->where('direction', FinanceTransactionDirection::In->value)->sum('amount');
        $cashOut = (float) $txThisMonth()->where('direction', FinanceTransactionDirection::Out->value)->sum('amount');
        $netCashMovement = $cashIn - $cashOut;

        // Pending COD is a running BALANCE (created minus CLOSED, all
        // time), not a monthly flow — mirrors how "unpaid expenses" above is
        // also a running total rather than scoped to this month. "Closed"
        // covers all three ways a receivable stops being pending — see
        // FinanceTransactionType::codClosingTypes() — not just the ad-hoc
        // single-order "mark collected" action.
        $codCreatedTotal = (float) FinanceTransaction::query()->where('type', FinanceTransactionType::CodReceivableCreated->value)->sum('amount');
        $codClosedTotal = (float) FinanceTransaction::query()->whereIn('type', FinanceTransactionType::codClosingTypes())->sum('amount');
        $codPending = max(0.0, $codCreatedTotal - $codClosedTotal);

        $recentTransactions = FinanceTransaction::query()
            ->with(['account:id,name,type'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'period' => ['from' => $monthStart->toDateString(), 'to' => $monthEnd->toDateString()],
            'cards' => [
                'expenses_this_month' => (float) $expensesThisMonth,
                'paid_this_month' => (float) $paidThisMonth,
                'unpaid_this_month' => (float) $unpaidThisMonth,
                'unpaid_total' => (float) $unpaidTotal->sum('amount'),
                'unpaid_count' => (clone $unpaidTotal)->count(),
                'overdue_total' => (float) $overdueTotal->sum('amount'),
                'overdue_count' => (clone $overdueTotal)->count(),
                'upcoming_recurring_count' => $upcomingRecurring->count(),
                'upcoming_recurring_total' => (float) $upcomingRecurring->sum('amount'),
                'sales_created' => $salesCreated,
                'money_collected' => $moneyCollected,
                'cod_pending' => $codPending,
                'refunds_this_month' => $refundsThisMonth,
                'net_cash_movement' => $netCashMovement,
            ],
            'top_category' => $topCategory ? [
                'category' => $topCategory->category,
                'total' => (float) $topCategory->total,
            ] : null,
            'cash_out_by_method' => $cashOutByMethod->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ])->values(),
            'upcoming_recurring' => $upcomingRecurring->values(),
            'recent_expenses' => $recentExpenses->values(),
            'recent_transactions' => $recentTransactions->values(),
        ];
    }
}
