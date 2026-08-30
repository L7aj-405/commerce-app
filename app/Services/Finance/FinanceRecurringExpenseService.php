<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceExpenseStatus;
use App\Enums\FinanceRecurringStatus;
use App\Models\FinanceExpense;
use App\Models\FinanceRecurringExpense;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinanceRecurringExpenseService
{
    public const SOURCE_TYPE = 'recurring_expense';

    public function __construct(private readonly FinanceExpenseService $expenses) {}

    /** Safety cap on catch-up iterations per recurring expense in a single run. */
    private const MAX_CATCH_UP_PERIODS = 500;

    public function create(Organization $organization, array $data): FinanceRecurringExpense
    {
        return FinanceRecurringExpense::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'category_id' => $data['category_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'MAD',
            'frequency' => $data['frequency'],
            'starts_at' => $data['starts_at'],
            'next_due_at' => $data['next_due_at'],
            'reminder_days_before' => $data['reminder_days_before'] ?? 7,
            'auto_create_expense' => $data['auto_create_expense'] ?? true,
            'generated_expense_status' => $data['generated_expense_status'] ?? FinanceExpenseStatus::Unpaid,
            'status' => FinanceRecurringStatus::Active,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function update(FinanceRecurringExpense $recurring, array $data): FinanceRecurringExpense
    {
        $recurring->update([
            'store_id' => $data['store_id'] ?? null,
            'category_id' => $data['category_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? $recurring->currency,
            'frequency' => $data['frequency'],
            'starts_at' => $data['starts_at'],
            'next_due_at' => $data['next_due_at'],
            'reminder_days_before' => $data['reminder_days_before'] ?? $recurring->reminder_days_before,
            'auto_create_expense' => $data['auto_create_expense'] ?? $recurring->auto_create_expense,
            'generated_expense_status' => $data['generated_expense_status'] ?? $recurring->generated_expense_status,
            'notes' => $data['notes'] ?? null,
        ]);

        return $recurring->refresh();
    }

    public function pause(FinanceRecurringExpense $recurring): FinanceRecurringExpense
    {
        $recurring->update(['status' => FinanceRecurringStatus::Paused]);

        return $recurring->refresh();
    }

    public function resume(FinanceRecurringExpense $recurring): FinanceRecurringExpense
    {
        $recurring->update(['status' => FinanceRecurringStatus::Active]);

        return $recurring->refresh();
    }

    public function cancel(FinanceRecurringExpense $recurring): FinanceRecurringExpense
    {
        $recurring->update(['status' => FinanceRecurringStatus::Cancelled]);

        return $recurring->refresh();
    }

    /**
     * Generate due expenses for every active recurring expense across every
     * organization (this is the scheduled/console entry point, so it
     * deliberately runs unscoped). Idempotent: re-running for the same due
     * date never creates a duplicate expense.
     *
     * @return array{processed: int, generated: int, skipped_existing: int, periods_advanced: int}
     */
    public function generateDue(?CarbonImmutable $asOf = null): array
    {
        $today = ($asOf ?? CarbonImmutable::now())->startOfDay();

        $summary = ['processed' => 0, 'generated' => 0, 'skipped_existing' => 0, 'periods_advanced' => 0];

        FinanceRecurringExpense::withoutOrganizationTenancy(function () use ($today, &$summary) {
            FinanceRecurringExpense::query()
                ->where('status', FinanceRecurringStatus::Active)
                // whereDate (not a plain where) — a `date`-cast column is stored as a
                // full 'Y-m-d 00:00:00' string on drivers without a real DATE type
                // (SQLite), so a plain string comparison against a bare 'Y-m-d' value
                // wrongly excludes rows due exactly "today" (longer string sorts higher).
                ->whereDate('next_due_at', '<=', $today->toDateString())
                ->chunkById(50, function ($recurringExpenses) use ($today, &$summary) {
                    foreach ($recurringExpenses as $recurring) {
                        $summary['processed']++;
                        $this->generateOneDue($recurring, $today, $summary);
                    }
                });
        });

        Log::info('finance:generate-recurring-expenses summary', $summary);

        return $summary;
    }

    private function generateOneDue(FinanceRecurringExpense $recurring, CarbonImmutable $today, array &$summary): void
    {
        $iterations = 0;

        while ($recurring->next_due_at->lte($today) && $iterations < self::MAX_CATCH_UP_PERIODS) {
            $iterations++;
            $dueDate = $recurring->next_due_at;

            DB::transaction(function () use ($recurring, $dueDate, &$summary) {
                $alreadyGenerated = FinanceExpense::withoutOrganizationTenancy(
                    fn () => FinanceExpense::withTrashed()
                        ->where('recurring_expense_id', $recurring->id)
                        ->whereDate('expense_date', $dueDate)
                        ->exists(),
                );

                if ($alreadyGenerated) {
                    $summary['skipped_existing']++;
                } elseif ($recurring->auto_create_expense) {
                    $this->generateExpenseFor($recurring, $dueDate);
                    $summary['generated']++;
                }

                $nextDueAt = $recurring->frequency->advance($dueDate);

                $recurring->forceFill([
                    'next_due_at' => $nextDueAt,
                    'last_generated_at' => now(),
                ])->save();

                $summary['periods_advanced']++;
            });

            $recurring->refresh();
        }
    }

    private function generateExpenseFor(FinanceRecurringExpense $recurring, CarbonImmutable $dueDate): FinanceExpense
    {
        $status = $recurring->generated_expense_status;

        $expense = FinanceExpense::withoutOrganizationTenancy(fn () => FinanceExpense::query()->create([
            'organization_id' => $recurring->organization_id,
            'store_id' => $recurring->store_id,
            'category_id' => $recurring->category_id,
            'vendor_id' => $recurring->vendor_id,
            'recurring_expense_id' => $recurring->id,
            'title' => $recurring->title,
            'description' => $recurring->description,
            'amount' => $recurring->amount,
            'currency' => $recurring->currency,
            'expense_date' => $dueDate,
            'due_date' => $dueDate,
            'status' => $status,
            'paid_at' => $status === FinanceExpenseStatus::Paid ? now() : null,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $recurring->id,
        ]));

        // Generated already-paid (generated_expense_status: paid) — write the
        // same idempotent expense_paid ledger entry markPaid() would, so a
        // recurring expense configured to auto-generate as paid still shows
        // up as real cash out, not just a Phase-1 status flag.
        $this->expenses->recordPaidTransactionIfNeeded($expense);

        return $expense;
    }
}
