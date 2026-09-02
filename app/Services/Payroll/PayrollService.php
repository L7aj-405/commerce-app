<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\EmployeeEmploymentStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Services\Finance\FinanceTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll periods/items are salary DUE, never cash on their own —
 * calculate()/approve() never write to finance_transactions. Only pay()
 * does, and only for the net amount actually paid, only once per item (see
 * its own docblock). This is why salaries can't be treated as ordinary
 * recurring expenses: an expense's "paid" flag and its ledger entry are the
 * same moment, but payroll needs a reviewable "this is what's due" state
 * (calculated → approved) BEFORE any cash commitment exists.
 */
class PayrollService
{
    public function __construct(
        private readonly EmployeeSalaryService $salaries,
        private readonly FinanceTransactionService $transactions,
    ) {}

    public function createPeriod(Organization $organization, User $createdBy, array $data): PayrollPeriod
    {
        return PayrollPeriod::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'pay_date' => $data['pay_date'] ?? null,
            'status' => PayrollPeriodStatus::Draft,
            'created_by' => $createdBy->id,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Creates/refreshes one payroll_item per active employee in scope —
     * never a finance_transaction. Safe to call repeatedly: an item already
     * approved/paid/cancelled is left completely untouched (its manually-set
     * bonus/deduction/notes are never overwritten either), and re-running
     * only ever updates-or-creates by (payroll_period_id, employee_id) —
     * the DB unique index backs this up, so a race can't duplicate a line.
     */
    public function calculate(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->isCancelled()) {
            throw ValidationException::withMessages(['period' => 'This payroll period is cancelled.']);
        }

        DB::transaction(function () use ($period) {
            $employees = Employee::query()
                ->where('organization_id', $period->organization_id)
                ->where('employment_status', EmployeeEmploymentStatus::Active->value)
                ->when($period->store_id, fn ($q) => $q->where(fn ($sub) => $sub->where('store_id', $period->store_id)->orWhereNull('store_id')))
                ->get();

            $asOf = CarbonImmutable::parse($period->period_end);

            foreach ($employees as $employee) {
                $existing = PayrollItem::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->first();

                // Never touch a line that's already been reviewed/paid/cancelled.
                if ($existing !== null && $existing->status !== PayrollItemStatus::Pending) {
                    continue;
                }

                $profile = $this->salaries->profileAsOf($employee, $asOf);
                $baseAmount = round((float) ($profile?->base_salary ?? 0), 2);

                if ($existing !== null) {
                    // Recalculation: refresh the salary-derived base amount
                    // only — keep whatever bonus/deduction/advance the user
                    // already set on this still-pending line.
                    $bonus = (float) $existing->bonus_amount;
                    $deduction = (float) $existing->deduction_amount;
                    $advanceDeduction = (float) $existing->advance_deduction_amount;

                    $existing->update([
                        'salary_profile_id' => $profile?->id,
                        'base_amount' => $baseAmount,
                        'currency' => $profile?->currency ?? $existing->currency,
                        'net_amount' => round($baseAmount + $bonus - $deduction - $advanceDeduction, 2),
                    ]);
                } else {
                    PayrollItem::query()->create([
                        'organization_id' => $period->organization_id,
                        'payroll_period_id' => $period->id,
                        'employee_id' => $employee->id,
                        'salary_profile_id' => $profile?->id,
                        'base_amount' => $baseAmount,
                        'bonus_amount' => 0,
                        'deduction_amount' => 0,
                        'advance_deduction_amount' => 0,
                        'net_amount' => $baseAmount,
                        'currency' => $profile?->currency ?? 'MAD',
                        'status' => PayrollItemStatus::Pending,
                    ]);
                }
            }

            if ($period->status === PayrollPeriodStatus::Draft) {
                $period->update(['status' => PayrollPeriodStatus::Calculated]);
            }
        });

        return $period->refresh();
    }

    /**
     * Manual bonus/deduction/notes on a still-pending line — the "bonus
     * foundation, not a full bonus engine" the spec asks for. A future
     * automatic-bonus phase can populate `bonus_amount` the same way this
     * does, without any schema change.
     */
    public function updateItem(PayrollItem $item, array $data): PayrollItem
    {
        $this->guardEditable($item);

        $bonus = round((float) ($data['bonus_amount'] ?? $item->bonus_amount), 2);
        $deduction = round((float) ($data['deduction_amount'] ?? $item->deduction_amount), 2);
        $advanceDeduction = round((float) ($data['advance_deduction_amount'] ?? $item->advance_deduction_amount), 2);

        $item->update([
            'bonus_amount' => $bonus,
            'deduction_amount' => $deduction,
            'advance_deduction_amount' => $advanceDeduction,
            'net_amount' => round((float) $item->base_amount + $bonus - $deduction - $advanceDeduction, 2),
            'notes' => $data['notes'] ?? $item->notes,
        ]);

        return $item->refresh();
    }

    /** Reviews every still-pending line in the period at once (see FinanceCodSettlementService for the same "batch approve" shape used elsewhere in Finance). */
    public function approvePeriod(PayrollPeriod $period, User $approver): PayrollPeriod
    {
        DB::transaction(function () use ($period, $approver) {
            $period->items()->where('status', PayrollItemStatus::Pending->value)->each(
                fn (PayrollItem $item) => $item->update(['status' => PayrollItemStatus::Approved])
            );

            $period->update([
                'status' => PayrollPeriodStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);
        });

        return $period->refresh();
    }

    /**
     * Pays exactly one item — books ONE salary_paid cash-out transaction for
     * net_amount. Idempotent both ways: an already-paid item is returned
     * as-is (FinanceTransactionService::record() would also no-op on a
     * repeated attempt, this just avoids re-touching paid_at/account_id
     * too). A cancelled item can never be paid.
     */
    public function pay(PayrollItem $item, User $actor, string $accountId, ?string $reference = null): PayrollItem
    {
        if ($item->isCancelled()) {
            throw ValidationException::withMessages(['item' => 'This payroll item is cancelled and cannot be paid.']);
        }

        if ($item->isPaid()) {
            return $item;
        }

        return DB::transaction(function () use ($item, $actor, $accountId, $reference) {
            $item->update([
                'status' => PayrollItemStatus::Paid,
                'account_id' => $accountId,
                'paid_at' => now(),
                'paid_by' => $actor->id,
                'reference' => $reference,
            ]);

            $item->loadMissing(['employee', 'payrollPeriod']);

            $this->transactions->record([
                'organization_id' => $item->organization_id,
                'store_id' => $item->payrollPeriod->store_id,
                'account_id' => $accountId,
                'direction' => FinanceTransactionDirection::Out,
                'type' => FinanceTransactionType::SalaryPaid,
                'amount' => (float) $item->net_amount,
                'currency' => $item->currency,
                'occurred_at' => $item->paid_at,
                'source_type' => PayrollItem::class,
                'source_id' => $item->id,
                'reference' => $reference,
                'description' => "Salary paid — {$item->employee->display_name}",
                'created_by' => $actor->id,
                'metadata' => [
                    'employee_id' => $item->employee_id,
                    'payroll_period_id' => $item->payroll_period_id,
                    'base_amount' => (float) $item->base_amount,
                    'bonus_amount' => (float) $item->bonus_amount,
                    'deduction_amount' => (float) $item->deduction_amount,
                    'advance_deduction_amount' => (float) $item->advance_deduction_amount,
                ],
            ]);

            $this->syncPeriodPaidStatus($item->payrollPeriod);

            return $item->refresh();
        });
    }

    /** Pays every currently-approved item in the period — a plain loop over pay(), which is itself idempotent, so a partial failure never double-pays what already went through. */
    public function payAll(PayrollPeriod $period, User $actor, string $accountId): PayrollPeriod
    {
        $period->items()->where('status', PayrollItemStatus::Approved->value)->get()
            ->each(fn (PayrollItem $item) => $this->pay($item, $actor, $accountId));

        return $period->refresh();
    }

    /**
     * Cancels a payroll line. If it was never paid, this is a pure status
     * flip — nothing to unwind. If it WAS paid, the cash already moved: per
     * the same rule FinanceExpenseService follows, that transaction is
     * never deleted — a reversing salary_payment_reversed entry is booked
     * for the same amount instead, so the paid-then-cancelled history stays
     * fully auditable.
     */
    public function cancelItem(PayrollItem $item, User $actor, ?string $reason = null): PayrollItem
    {
        $wasPaid = $item->isPaid();

        return DB::transaction(function () use ($item, $actor, $reason, $wasPaid) {
            $item->update(['status' => PayrollItemStatus::Cancelled]);

            if ($wasPaid) {
                $item->loadMissing(['employee', 'payrollPeriod']);

                $this->transactions->record([
                    'organization_id' => $item->organization_id,
                    'store_id' => $item->payrollPeriod->store_id,
                    'account_id' => $item->account_id,
                    'direction' => FinanceTransactionDirection::In,
                    'type' => FinanceTransactionType::SalaryPaymentReversed,
                    'amount' => (float) $item->net_amount,
                    'currency' => $item->currency,
                    'occurred_at' => now(),
                    'source_type' => PayrollItem::class,
                    'source_id' => $item->id,
                    'created_by' => $actor->id,
                    'description' => "Salary payment reversed — {$item->employee->display_name}" . ($reason ? " ({$reason})" : ''),
                    'metadata' => [
                        'employee_id' => $item->employee_id,
                        'payroll_period_id' => $item->payroll_period_id,
                        'reason' => $reason,
                    ],
                ]);
            }

            return $item->refresh();
        });
    }

    private function guardEditable(PayrollItem $item): void
    {
        if ($item->status !== PayrollItemStatus::Pending) {
            throw ValidationException::withMessages([
                'item' => 'This payroll item is no longer pending — it has already been approved, paid, or cancelled and can no longer be edited directly.',
            ]);
        }
    }

    /** A period is "paid" once every non-cancelled item in it is paid — never set by hand, always derived after each individual payment. */
    private function syncPeriodPaidStatus(PayrollPeriod $period): void
    {
        $items = $period->items()->get();
        $active = $items->reject(fn (PayrollItem $i) => $i->status === PayrollItemStatus::Cancelled);

        if ($active->isNotEmpty() && $active->every(fn (PayrollItem $i) => $i->status === PayrollItemStatus::Paid)) {
            $period->update(['status' => PayrollPeriodStatus::Paid]);
        }
    }
}
