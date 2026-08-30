<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceExpenseCategory;
use App\Models\User;

/**
 * Tenancy + permission rules for expense categories. Every check first
 * confirms the category belongs to the acting user's active organization,
 * so a permission granted in one organization can never touch another's data.
 */
class FinanceExpenseCategoryPolicy
{
    private function inActiveOrganization(User $user, FinanceExpenseCategory $category): bool
    {
        return $category->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceExpenseCategory $category): bool
    {
        return $this->inActiveOrganization($user, $category)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_categories');
    }

    public function update(User $user, FinanceExpenseCategory $category): bool
    {
        return $this->inActiveOrganization($user, $category)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_categories');
    }

    public function delete(User $user, FinanceExpenseCategory $category): bool
    {
        return $this->update($user, $category) && ! $category->is_system && ! $category->isInUse();
    }
}
