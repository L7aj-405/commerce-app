<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceCourierDeposit;
use App\Models\User;

class FinanceCourierDepositPolicy
{
    private function inActiveOrganization(User $user, FinanceCourierDeposit $deposit): bool
    {
        return $deposit->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceCourierDeposit $deposit): bool
    {
        return $this->inActiveOrganization($user, $deposit)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cod_settlements');
    }

    public function update(User $user, FinanceCourierDeposit $deposit): bool
    {
        return $this->inActiveOrganization($user, $deposit)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cod_settlements');
    }
}
