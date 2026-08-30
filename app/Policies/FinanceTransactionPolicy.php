<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceTransaction;
use App\Models\User;

class FinanceTransactionPolicy
{
    private function inActiveOrganization(User $user, FinanceTransaction $transaction): bool
    {
        return $transaction->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view_reports');
    }

    public function view(User $user, FinanceTransaction $transaction): bool
    {
        return $this->inActiveOrganization($user, $transaction)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view_reports');
    }

    /** Manual adjustments only — automatic ledger writes go through the service layer directly. */
    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cashflow');
    }
}
