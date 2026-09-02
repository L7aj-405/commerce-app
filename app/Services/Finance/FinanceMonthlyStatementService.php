<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceCodSettlementStatus;
use App\Enums\FinanceCourierDepositStatus;
use App\Enums\FinanceExpenseJustificationStatus;
use App\Enums\FinanceExpenseOwnerReviewStatus;
use App\Enums\FinanceExpenseStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Enums\PayrollItemStatus;
use App\Models\FinanceCodSettlement;
use App\Models\FinanceCourierDeposit;
use App\Models\FinanceExpense;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\PayrollItem;
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
    public function __construct(
        private readonly FinanceCodPayoutPeriodService $payoutPeriods,
    ) {}

    public function forMonth(string $month, ?string $storeId = null, ?Organization $organization = null): array
    {
        $monthStart = CarbonImmutable::parse($month . '-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        // whereDate (not whereBetween/where) throughout this method — a `date`-cast
        // column is stored as a full 'Y-m-d 00:00:00' string on drivers without a
        // real DATE type (SQLite), so a plain string comparison against a bare
        // 'Y-m-d' value wrongly excludes same-day rows (longer string sorts higher).
        $base = fn (): Builder => FinanceExpense::query()
            ->with(['category:id,name', 'vendor:id,name', 'store:id,name'])
            ->withCount('documents')
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
            ->withCount('documents')
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

        // Payroll — same ledger-based rule as expenses_paid above: this must
        // only ever reflect finance_transactions (real cash), never a live
        // query against payroll_items.status, so a later-cancelled/reversed
        // item doesn't retroactively erase a real historical payment. Salary
        // DUE (not yet paid) is a completely separate, live, NOT month-scoped
        // snapshot — see `payroll` below — never mixed into this figure.
        $salariesPaidTx = $transactions->where('type', FinanceTransactionType::SalaryPaid);
        $advancesPaidTx = $transactions->where('type', FinanceTransactionType::EmployeeAdvancePaid);

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

        // External carrier COD, broken out clearly per the Finance spec:
        // gross COD / expected fees / expected net are all NON-CASH
        // (informational, from stored fee snapshots) — only
        // actual_received_amount ever became real cash (already counted
        // once, correctly, inside cashflow.collections above via the ledger's
        // own cod_settlement_received transactions). This section never adds
        // a second cash figure, it only explains the one that's already there.
        $externalCodBase = fn (): Builder => FinanceCodSettlement::query()
            ->whereNotNull('delivery_provider_id')
            ->whereIn('status', [
                FinanceCodSettlementStatus::Settled->value,
                FinanceCodSettlementStatus::Partial->value,
                FinanceCodSettlementStatus::Disputed->value,
            ])
            ->whereDate('received_at', '>=', $monthStart->toDateString())
            ->whereDate('received_at', '<=', $monthEnd->toDateString())
            ->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));

        $externalCodSettlementsThisMonth = $externalCodBase()->with('provider:id,name')->get();

        $externalCodByProvider = $externalCodSettlementsThisMonth
            ->groupBy('delivery_provider_id')
            ->map(fn ($group) => [
                'delivery_provider_id' => $group->first()->delivery_provider_id,
                'provider_name' => $group->first()->provider?->name ?? 'Unknown provider',
                'settlements_count' => $group->count(),
                'gross_cod' => (float) $group->sum('gross_cod_amount'),
                'expected_fees' => (float) $group->sum('delivery_fees'),
                'expected_net' => (float) $group->sum('expected_net_amount'),
                'actual_received' => (float) $group->sum(fn (FinanceCodSettlement $s) => $s->actual_received_amount ?? $s->net_received),
                'variance' => (float) $group->sum('variance_amount'),
                'disputed_count' => $group->where('status', FinanceCodSettlementStatus::Disputed)->count(),
                'partial_count' => $group->where('status', FinanceCodSettlementStatus::Partial)->count(),
            ])
            ->sortByDesc('gross_cod')
            ->values();

        // Live, org-wide snapshot of what's STILL sitting with a provider
        // right now (delivered, not yet even drafted into a settlement) —
        // deliberately NOT month-scoped (a payout period rarely aligns with
        // a calendar month) and never cash, since none of it has a bank
        // transfer behind it yet.
        $pendingProviderPeriods = $organization !== null ? $this->payoutPeriods->pendingPeriods($organization) : collect();

        return [
            'month' => $monthStart->format('Y-m'),
            'store_id' => $storeId,
            'cashflow' => [
                'sales_created' => ['count' => $salesCreated->count(), 'amount' => (float) $salesCreated->sum('amount')],
                'collections' => ['count' => $collections->count(), 'amount' => (float) $collections->sum('amount')],
                'pending_receivables_at_month_end' => $pendingReceivablesAtMonthEnd,
                'expenses_paid' => ['count' => $expensesPaidTx->count(), 'amount' => (float) $expensesPaidTx->sum('amount')],
                'salaries_paid' => ['count' => $salariesPaidTx->count(), 'amount' => (float) $salariesPaidTx->sum('amount')],
                'advances_paid' => ['count' => $advancesPaidTx->count(), 'amount' => (float) $advancesPaidTx->sum('amount')],
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
            'external_cod' => [
                'by_provider' => $externalCodByProvider,
                'gross_cod' => (float) $externalCodByProvider->sum('gross_cod'),
                'expected_fees' => (float) $externalCodByProvider->sum('expected_fees'),
                'expected_net' => (float) $externalCodByProvider->sum('expected_net'),
                'actual_received' => (float) $externalCodByProvider->sum('actual_received'),
                'variance' => (float) $externalCodByProvider->sum('variance'),
                'disputed_count' => (int) $externalCodByProvider->sum('disputed_count'),
                'partial_count' => (int) $externalCodByProvider->sum('partial_count'),
                // Live, not month-scoped — see $pendingProviderPeriods above.
                'pending_periods' => $pendingProviderPeriods,
                'pending_delivered_unpaid_cod' => (float) $pendingProviderPeriods->sum('gross_cod'),
                'overdue_periods_count' => $pendingProviderPeriods->where('status', 'overdue')->count(),
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
            // Internal justification / owner-review split (see
            // FinanceExpenseService's class docblock) — deliberately its own
            // section so `totals` above (Phase 1, unchanged) never mixes an
            // official-invoice expense's amount with an internal-only one's.
            // `fiscal_ready` is the one figure an accountant/fiscal export
            // should ever sum: everything else here is internal transparency,
            // not external legal proof.
            'justification' => (function () use ($all, $expensesPaidTx) {
                $active = $all->where('status', '!==', FinanceExpenseStatus::Cancelled);
                $documented = $active->where('justification_status', FinanceExpenseJustificationStatus::Documented);
                $internalVoucher = $active->where('justification_status', FinanceExpenseJustificationStatus::InternalOnly);
                $noDocument = $active->where('justification_status', FinanceExpenseJustificationStatus::NeedsReview);
                $pendingReview = $active->where('owner_review_status', FinanceExpenseOwnerReviewStatus::Pending);
                $needsMoreInfo = $active->where('owner_review_status', FinanceExpenseOwnerReviewStatus::NeedsMoreInfo);
                $rejectedOrCancelled = $all->filter(fn (FinanceExpense $e) => $e->status === FinanceExpenseStatus::Cancelled || $e->owner_review_status === FinanceExpenseOwnerReviewStatus::Rejected);

                $sum = fn ($group) => ['count' => $group->count(), 'amount' => (float) $group->sum('amount')];

                return [
                    'official_documented' => $sum($documented),
                    'internal_cash_voucher' => $sum($internalVoucher),
                    'missing_no_document' => $sum($noDocument),
                    'rejected_or_cancelled' => $sum($rejectedOrCancelled),
                    'pending_owner_review' => $sum($pendingReview),
                    'needs_more_info' => $sum($needsMoreInfo),
                    // The only expense total that belongs in a fiscal/
                    // accountant export — see the section docblock above.
                    'fiscal_ready_amount' => (float) $documented->sum('amount'),
                    'internal_only_amount' => (float) $internalVoucher->sum('amount') + (float) $noDocument->sum('amount'),
                    // Real cash paid out THIS MONTH, from the ledger — same
                    // figure as cashflow.expenses_paid above, repeated here
                    // so the three totals (fiscal-ready / internal-only /
                    // cashflow) are readable side by side. Deliberately NOT
                    // fiscal_ready_amount + internal_only_amount: those are
                    // scoped by expense_date regardless of paid status,
                    // cashflow_total is scoped by the actual payment date.
                    'cashflow_total' => (float) $expensesPaidTx->sum('amount'),
                ];
            })(),
            // Payroll — salary DUE (calculated/approved, not yet paid) is
            // NEVER cash and is deliberately a LIVE, org-wide snapshot (like
            // external_cod.pending_periods above), not month-scoped: a
            // payroll period rarely aligns with a calendar month. Salary/
            // advance PAID totals above (cashflow.salaries_paid/advances_paid)
            // are the only payroll figures that ever count as cash — this
            // section explains them, never adds a second cash number.
            'payroll' => (function () use ($organization, $storeId, $salariesPaidTx, $advancesPaidTx) {
                $dueItems = PayrollItem::query()
                    ->whereIn('status', [PayrollItemStatus::Pending->value, PayrollItemStatus::Approved->value])
                    ->when($organization, fn (Builder $q) => $q->where('organization_id', $organization->id))
                    ->when($storeId, fn (Builder $q) => $q->whereHas('payrollPeriod', fn (Builder $p) => $p->where('store_id', $storeId)))
                    ->with('employee:id,display_name,store_id')
                    ->get();

                $byEmployee = $dueItems->groupBy('employee_id')
                    ->map(fn ($group) => [
                        'employee_id' => $group->first()->employee_id,
                        'employee_name' => $group->first()->employee?->display_name,
                        'count' => $group->count(),
                        'net_amount' => (float) $group->sum('net_amount'),
                    ])
                    ->sortByDesc('net_amount')
                    ->values();

                $byStore = $dueItems->groupBy(fn (PayrollItem $item) => $item->employee?->store_id ?? '__organization')
                    ->map(fn ($group) => [
                        'store_id' => $group->first()->employee?->store_id,
                        'count' => $group->count(),
                        'net_amount' => (float) $group->sum('net_amount'),
                    ])
                    ->values();

                return [
                    'salary_due' => ['count' => $dueItems->count(), 'amount' => (float) $dueItems->sum('net_amount')],
                    'salaries_paid_this_month' => ['count' => $salariesPaidTx->count(), 'amount' => (float) $salariesPaidTx->sum('amount')],
                    'advances_paid_this_month' => ['count' => $advancesPaidTx->count(), 'amount' => (float) $advancesPaidTx->sum('amount')],
                    'by_employee' => $byEmployee,
                    'by_store' => $byStore,
                ];
            })(),
            'upcoming_unpaid_due' => $upcomingUnpaidDue,
            'export_rows' => $all->map(fn (FinanceExpense $e) => [
                'id' => $e->id,
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
                'document_count' => $e->documents_count,
                'justification_type' => $e->justification_type?->value,
                'justification_status' => $e->justification_status?->value,
                'owner_review_status' => $e->owner_review_status?->value,
            ])->values(),
        ];
    }
}
