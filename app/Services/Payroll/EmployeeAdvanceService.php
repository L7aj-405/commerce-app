<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\EmployeeAdvanceStatus;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Enums\PayrollItemStatus;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\Finance\FinanceTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requesting/approving an advance never touches the ledger — only actually
 * PAYING one does (employee_advance_paid). Deducting an already-paid
 * advance from a payroll item reduces that item's net_amount but is NOT a
 * new cash movement (the cash already left when the advance was paid) — see
 * applyToPayrollItem(), which never calls FinanceTransactionService.
 */
class EmployeeAdvanceService
{
    public function __construct(
        private readonly FinanceTransactionService $transactions,
    ) {}

    public function create(Employee $employee, User $createdBy, array $data): EmployeeAdvance
    {
        return EmployeeAdvance::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'MAD',
            'advance_date' => $data['advance_date'] ?? now()->toDateString(),
            'status' => EmployeeAdvanceStatus::Pending,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function approve(EmployeeAdvance $advance, User $approver): EmployeeAdvance
    {
        if ($advance->status !== EmployeeAdvanceStatus::Pending) {
            return $advance;
        }

        $advance->update([
            'status' => EmployeeAdvanceStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        return $advance->refresh();
    }

    /** Idempotent — an already-paid/deducted advance is returned as-is, never double-paid. */
    public function pay(EmployeeAdvance $advance, User $actor, string $accountId, ?string $reference = null): EmployeeAdvance
    {
        if (in_array($advance->status, [EmployeeAdvanceStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['advance' => 'This advance is cancelled and cannot be paid.']);
        }

        if ($advance->isPaid()) {
            return $advance;
        }

        return DB::transaction(function () use ($advance, $actor, $accountId, $reference) {
            $advance->update([
                'status' => EmployeeAdvanceStatus::Paid,
                'account_id' => $accountId,
                'paid_by' => $actor->id,
                'paid_at' => now(),
            ]);

            $advance->loadMissing('employee');

            $this->transactions->record([
                'organization_id' => $advance->organization_id,
                'account_id' => $accountId,
                'direction' => FinanceTransactionDirection::Out,
                'type' => FinanceTransactionType::EmployeeAdvancePaid,
                'amount' => (float) $advance->amount,
                'currency' => $advance->currency,
                'occurred_at' => $advance->paid_at,
                'source_type' => EmployeeAdvance::class,
                'source_id' => $advance->id,
                'reference' => $reference,
                'description' => "Advance paid — {$advance->employee->display_name}",
                'created_by' => $actor->id,
                'metadata' => ['employee_id' => $advance->employee_id],
            ]);

            return $advance->refresh();
        });
    }

    /** Same reversal rule as PayrollService::cancelItem() — a paid advance is never deleted, just reversed. */
    public function cancel(EmployeeAdvance $advance, User $actor, ?string $reason = null): EmployeeAdvance
    {
        if ($advance->status === EmployeeAdvanceStatus::Deducted) {
            throw ValidationException::withMessages(['advance' => 'This advance has already been deducted from a payroll payment and can no longer be cancelled directly — reverse the payroll item instead.']);
        }

        $wasPaid = $advance->isPaid();

        return DB::transaction(function () use ($advance, $actor, $reason, $wasPaid) {
            $advance->update(['status' => EmployeeAdvanceStatus::Cancelled]);

            if ($wasPaid) {
                $advance->loadMissing('employee');

                $this->transactions->record([
                    'organization_id' => $advance->organization_id,
                    'account_id' => $advance->account_id,
                    'direction' => FinanceTransactionDirection::In,
                    'type' => FinanceTransactionType::EmployeeAdvancePaymentReversed,
                    'amount' => (float) $advance->amount,
                    'currency' => $advance->currency,
                    'occurred_at' => now(),
                    'source_type' => EmployeeAdvance::class,
                    'source_id' => $advance->id,
                    'created_by' => $actor->id,
                    'description' => "Advance payment reversed — {$advance->employee->display_name}" . ($reason ? " ({$reason})" : ''),
                    'metadata' => ['employee_id' => $advance->employee_id, 'reason' => $reason],
                ]);
            }

            return $advance->refresh();
        });
    }

    /**
     * Applies an already-paid, not-yet-deducted advance to a still-pending
     * payroll item — reduces net_amount, never books a finance_transaction
     * (the cash already moved when the advance was paid; this only changes
     * how much salary cash is still owed). Guarded so the same advance can
     * never be applied twice.
     */
    public function applyToPayrollItem(EmployeeAdvance $advance, PayrollItem $item): PayrollItem
    {
        if (! $advance->isAvailableForDeduction()) {
            throw ValidationException::withMessages(['advance' => 'This advance is not available to deduct (already deducted, not yet paid, or cancelled).']);
        }

        if ($item->status !== PayrollItemStatus::Pending) {
            throw ValidationException::withMessages(['item' => 'This payroll item is no longer pending and cannot be changed.']);
        }

        if ($advance->employee_id !== $item->employee_id) {
            throw ValidationException::withMessages(['advance' => 'This advance belongs to a different employee.']);
        }

        return DB::transaction(function () use ($advance, $item) {
            $newAdvanceDeduction = round((float) $item->advance_deduction_amount + (float) $advance->amount, 2);

            $item->update([
                'advance_deduction_amount' => $newAdvanceDeduction,
                'net_amount' => round((float) $item->base_amount + (float) $item->bonus_amount - (float) $item->deduction_amount - $newAdvanceDeduction, 2),
            ]);

            $advance->update([
                'status' => EmployeeAdvanceStatus::Deducted,
                'deducted_in_payroll_item_id' => $item->id,
            ]);

            return $item->refresh();
        });
    }

    /** Advances an employee has actually been paid but hasn't yet had deducted from a payslip — what the payroll UI offers to apply. */
    public function availableForDeduction(Employee $employee): \Illuminate\Support\Collection
    {
        return EmployeeAdvance::query()
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeAdvanceStatus::Paid->value)
            ->whereNull('deducted_in_payroll_item_id')
            ->orderBy('advance_date')
            ->get();
    }
}
