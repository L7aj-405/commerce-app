<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceExpense;
use App\Models\User;

class FinanceExpensePolicy
{
    private function inActiveOrganization(User $user, FinanceExpense $expense): bool
    {
        return $expense->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceExpense $expense): bool
    {
        return $this->inActiveOrganization($user, $expense)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_expenses');
    }

    public function update(User $user, FinanceExpense $expense): bool
    {
        return $this->inActiveOrganization($user, $expense)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_expenses');
    }

    public function delete(User $user, FinanceExpense $expense): bool
    {
        return $this->update($user, $expense);
    }

    /** Marking an already-paid expense back to unpaid is the sensitive direction. */
    public function markUnpaid(User $user, FinanceExpense $expense): bool
    {
        return $this->update($user, $expense)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_expenses');
    }

    /** Approve/reject/request-more-info on an internal cash voucher / no-invoice expense's justification — owner/admin territory, deliberately separate from finance.manage_expenses (which staff who ENTER expenses may hold without being the one who reviews them). */
    public function review(User $user, FinanceExpense $expense): bool
    {
        return $this->inActiveOrganization($user, $expense)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.review_expenses');
    }
}
