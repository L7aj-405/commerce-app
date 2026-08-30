<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceRecurringExpense;
use App\Models\User;

class FinanceRecurringExpensePolicy
{
    private function inActiveOrganization(User $user, FinanceRecurringExpense $recurring): bool
    {
        return $recurring->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceRecurringExpense $recurring): bool
    {
        return $this->inActiveOrganization($user, $recurring)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_recurring');
    }

    public function update(User $user, FinanceRecurringExpense $recurring): bool
    {
        return $this->inActiveOrganization($user, $recurring)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_recurring');
    }

    public function delete(User $user, FinanceRecurringExpense $recurring): bool
    {
        return $this->update($user, $recurring);
    }
}
