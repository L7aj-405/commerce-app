<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceAccount;
use App\Models\User;

class FinanceAccountPolicy
{
    private function inActiveOrganization(User $user, FinanceAccount $account): bool
    {
        return $account->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceAccount $account): bool
    {
        return $this->inActiveOrganization($user, $account)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_accounts');
    }

    public function update(User $user, FinanceAccount $account): bool
    {
        return $this->inActiveOrganization($user, $account)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_accounts');
    }

    public function delete(User $user, FinanceAccount $account): bool
    {
        return $this->update($user, $account) && ! $account->isInUse();
    }
}
