<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceAccountType;
use App\Enums\FinanceExpenseStatus;
use App\Enums\FinancePaymentMethod;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceExpense;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FinanceExpenseService
{
    public function __construct(
        private readonly FinanceTransactionService $transactions,
        private readonly FinanceAccountService $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  from/to/category_id/vendor_id/status/payment_method/store_id
     */
    public function filteredQuery(array $filters = []): Builder
    {
        return FinanceExpense::query()
            ->with(['category:id,name,color,icon', 'vendor:id,name', 'store:id,name'])
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($filters['vendor_id'] ?? null, fn (Builder $q, $v) => $q->where('vendor_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, $v) => $q->where('payment_method', $v))
            ->when($filters['store_id'] ?? null, fn (Builder $q, $v) => $q->where('store_id', $v))
            ->orderByDesc('expense_date')
            ->orderByDesc('created_at');
    }

    /**
     * Creating always lands the expense as unpaid (Phase 1 behaviour,
     * unchanged) — so no ledger entry is written here. See
     * recordPaidTransactionIfNeeded() for the one path that CAN create an
     * already-paid expense (the recurring-expense generator).
     */
    public function create(Organization $organization, User $createdBy, array $data): FinanceExpense
    {
        return FinanceExpense::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'category_id' => $data['category_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'MAD',
            'expense_date' => $data['expense_date'],
            'due_date' => $data['due_date'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'status' => FinanceExpenseStatus::Unpaid,
            'created_by' => $createdBy->id,
        ]);
    }

    /**
     * Editing is always allowed for an UNPAID expense (no ledger entry
     * exists yet, so nothing can go stale). For a PAID expense, the fields
     * that fed the already-recorded `expense_paid` transaction — amount,
     * currency, payment method, expense date — are locked: the ledger row
     * is append-only and is never rewritten to match a later edit, so
     * silently changing those fields here would leave the expense record
     * and its own ledger history disagreeing about how much cash actually
     * moved. There's no correction-transaction UI yet, so the correct path
     * is: mark it back to unpaid first (records `expense_payment_reversed`,
     * see markUnpaid()), edit it, then mark it paid again (records a fresh
     * `expense_paid` for the corrected amount).
     */
    public function update(FinanceExpense $expense, array $data): FinanceExpense
    {
        $this->guardLedgerSensitiveEdit($expense, $data);

        $expense->update([
            'store_id' => $data['store_id'] ?? null,
            'category_id' => $data['category_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? $expense->currency,
            'expense_date' => $data['expense_date'],
            'due_date' => $data['due_date'] ?? null,
            'payment_method' => $data['payment_method'] ?? $expense->payment_method,
            'reference' => $data['reference'] ?? null,
        ]);

        return $expense->refresh();
    }

    private function guardLedgerSensitiveEdit(FinanceExpense $expense, array $data): void
    {
        if ($expense->status !== FinanceExpenseStatus::Paid) {
            return;
        }

        $newAmount = round((float) $data['amount'], 2);
        $currentAmount = round((float) $expense->amount, 2);

        $newCurrency = $data['currency'] ?? $expense->currency;

        // update() defaults a MISSING payment_method key to the expense's
        // current value (never a change) — only a payment_method key that
        // is actually PRESENT in $data can represent a real edit. When
        // present, blank('') must compare equal to a genuinely-null current
        // value, not be treated as "changed to empty string".
        $currentPaymentMethod = $expense->payment_method?->value;
        $paymentMethodChanged = array_key_exists('payment_method', $data)
            && (blank($data['payment_method']) ? null : $data['payment_method']) !== $currentPaymentMethod;

        $newDate = isset($data['expense_date']) ? Carbon::parse($data['expense_date'])->toDateString() : null;
        $currentDate = $expense->expense_date?->toDateString();

        $changed = $newAmount !== $currentAmount
            || $newCurrency !== $expense->currency
            || $paymentMethodChanged
            || ($newDate !== null && $newDate !== $currentDate);

        if ($changed) {
            throw ValidationException::withMessages([
                'amount' => 'This expense is already paid — its amount, currency, payment method and date are locked to match the recorded transaction. Mark it back to unpaid first (this records a correction), then edit it and mark it paid again.',
            ]);
        }
    }

    public function markPaid(FinanceExpense $expense, ?string $paymentMethod = null): FinanceExpense
    {
        $expense->update([
            'status' => FinanceExpenseStatus::Paid,
            'paid_at' => now(),
            'payment_method' => $paymentMethod ?? $expense->payment_method,
        ]);

        $expense = $expense->refresh();
        $this->recordPaidTransactionIfNeeded($expense);

        return $expense;
    }

    /**
     * A paid expense being walked back to unpaid is the sensitive direction
     * (FinanceExpensePolicy::markUnpaid() already gates it on the same
     * finance.manage_expenses permission as everything else — Phase 1's
     * permission model is unchanged). The ledger keeps the original
     * expense_paid row exactly as it is (append-only) and adds a reversing
     * `expense_payment_reversed` entry for the same amount, so the cash
     * history stays truthful instead of silently pretending the money was
     * never spent. This is its own transaction TYPE (not the generic
     * `manual_adjustment` used by the free-form "Add adjustment" action on
     * the Transactions page) so the ledger UI can label it plainly instead
     * of lumping it in with unrelated manual entries.
     */
    public function markUnpaid(FinanceExpense $expense): FinanceExpense
    {
        $wasPaid = $expense->status === FinanceExpenseStatus::Paid;

        $expense->update([
            'status' => FinanceExpenseStatus::Unpaid,
            'paid_at' => null,
        ]);

        $expense = $expense->refresh();

        if ($wasPaid) {
            $this->recordReversalIfNeeded($expense, 'Expense marked back to unpaid.');
        }

        return $expense;
    }

    public function cancel(FinanceExpense $expense): FinanceExpense
    {
        $wasPaid = $expense->status === FinanceExpenseStatus::Paid;

        $expense->update(['status' => FinanceExpenseStatus::Cancelled]);

        $expense = $expense->refresh();

        if ($wasPaid) {
            $this->recordReversalIfNeeded($expense, 'Expense cancelled after being paid.');
        }

        return $expense;
    }

    /**
     * A PAID expense (however it was created — manual or recurring-generated)
     * is NEVER hard-deleted: it already has ledger history, and hard-deleting
     * the expense row would make that history un-auditable from the Finance
     * side (no record of what the cash was originally for). It is cancelled
     * instead — cancel() records the reversing `expense_payment_reversed`
     * transaction and sets status to Cancelled, so the row stays for
     * audit/history views while dropping out of active expense totals.
     *
     * An UNPAID expense has no ledger entry to protect. A manually entered
     * one can be hard-deleted outright; one generated by a recurring expense
     * is cancelled instead — the generator relies on the row existing to
     * stay idempotent — but either way is "safe" (no cash impact).
     *
     * @return bool true if the expense was hard-deleted, false if cancelled instead
     */
    public function delete(FinanceExpense $expense): bool
    {
        if ($expense->status === FinanceExpenseStatus::Paid || $expense->recurring_expense_id !== null) {
            $this->cancel($expense);

            return false;
        }

        $expense->delete();

        return true;
    }

    /**
     * Writes an expense_paid cash-out transaction for a paid expense — but
     * ONLY if there isn't already an ACTIVE (unreversed) one. An expense can
     * be paid, marked back to unpaid (which reverses that payment), edited,
     * and paid again any number of times over its life — each such cycle
     * gets its own expense_paid/expense_payment_reversed pair, sharing one
     * `sequence` number, so a later cycle is never blocked by an earlier
     * one that was already fully reversed. Safe to call repeatedly (e.g.
     * clicking "mark paid" twice while already paid, or the
     * recurring-expense generator creating an expense already marked paid)
     * — see hasActiveUnreversedPayment().
     */
    public function recordPaidTransactionIfNeeded(FinanceExpense $expense): void
    {
        if ($expense->status !== FinanceExpenseStatus::Paid) {
            return;
        }

        $paidCount = $this->paidTransactionCount($expense);

        if ($paidCount > $this->reversalTransactionCount($expense)) {
            return; // an unreversed payment already exists for this cycle — idempotent no-op
        }

        $account = $this->accounts->resolveByType($expense->organization, $this->accountTypeFor($expense->payment_method));

        $this->transactions->record([
            'organization_id' => $expense->organization_id,
            'store_id' => $expense->store_id,
            'account_id' => $account?->id,
            'direction' => FinanceTransactionDirection::Out,
            'type' => FinanceTransactionType::ExpensePaid,
            'sequence' => $paidCount + 1,
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'occurred_at' => $expense->paid_at ?? $expense->expense_date,
            'source_type' => FinanceExpense::class,
            'source_id' => $expense->id,
            'reference' => $expense->reference,
            'description' => $expense->title,
        ]);
    }

    /**
     * Reverses the CURRENT active payment cycle, if one exists — never the
     * expense's current (possibly since-edited) amount. Finds the latest
     * unreversed expense_paid transaction and reverses THAT specific
     * amount, linking the two via metadata so the ledger can always answer
     * "what did this reversal undo".
     */
    private function recordReversalIfNeeded(FinanceExpense $expense, string $reason): void
    {
        $activePayment = $this->latestActiveUnreversedPayment($expense);

        if ($activePayment === null) {
            return; // this expense's cash never actually went out (or was already reversed) — nothing to reverse
        }

        $this->transactions->record([
            'organization_id' => $expense->organization_id,
            'store_id' => $expense->store_id,
            'direction' => FinanceTransactionDirection::In,
            'type' => FinanceTransactionType::ExpensePaymentReversed,
            'sequence' => $activePayment->sequence,
            'amount' => $activePayment->amount,
            'currency' => $activePayment->currency,
            'occurred_at' => now(),
            'source_type' => FinanceExpense::class,
            'source_id' => $expense->id,
            'description' => "Expense payment reversed — {$reason} (\"{$expense->title}\")",
            'metadata' => [
                'reverses_transaction_id' => $activePayment->id,
                'reversal_reason' => $reason,
            ],
        ]);
    }

    /** The most recent expense_paid transaction for this expense that has NOT yet been reversed, or null if there is none. */
    private function latestActiveUnreversedPayment(FinanceExpense $expense): ?FinanceTransaction
    {
        if ($this->paidTransactionCount($expense) <= $this->reversalTransactionCount($expense)) {
            return null;
        }

        return FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()
            ->where('source_type', FinanceExpense::class)
            ->where('source_id', $expense->id)
            ->where('type', FinanceTransactionType::ExpensePaid->value)
            ->orderByDesc('sequence')
            ->first());
    }

    private function paidTransactionCount(FinanceExpense $expense): int
    {
        return FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()
            ->where('source_type', FinanceExpense::class)
            ->where('source_id', $expense->id)
            ->where('type', FinanceTransactionType::ExpensePaid->value)
            ->count());
    }

    private function reversalTransactionCount(FinanceExpense $expense): int
    {
        return FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()
            ->where('source_type', FinanceExpense::class)
            ->where('source_id', $expense->id)
            ->where('type', FinanceTransactionType::ExpensePaymentReversed->value)
            ->count());
    }

    private function accountTypeFor(?FinancePaymentMethod $method): FinanceAccountType
    {
        return match ($method) {
            FinancePaymentMethod::Cash => FinanceAccountType::Cash,
            FinancePaymentMethod::Card => FinanceAccountType::Card,
            FinancePaymentMethod::BankTransfer, FinancePaymentMethod::Cheque => FinanceAccountType::Bank,
            FinancePaymentMethod::CodSettlement => FinanceAccountType::DeliveryCompany,
            default => FinanceAccountType::Other,
        };
    }
}
