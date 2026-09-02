<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollPeriod;
use App\Models\User;

class PayrollPeriodPolicy
{
    private function inActiveOrganization(User $user, PayrollPeriod $period): bool
    {
        return $period->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, PayrollPeriod $period): bool
    {
        return $this->inActiveOrganization($user, $period)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_payroll');
    }

    public function update(User $user, PayrollPeriod $period): bool
    {
        return $this->inActiveOrganization($user, $period)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_payroll');
    }
}
