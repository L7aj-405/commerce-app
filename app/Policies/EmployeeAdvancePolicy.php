<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmployeeAdvance;
use App\Models\User;

class EmployeeAdvancePolicy
{
    private function inActiveOrganization(User $user, EmployeeAdvance $advance): bool
    {
        return $advance->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function view(User $user, EmployeeAdvance $advance): bool
    {
        return $this->inActiveOrganization($user, $advance)
            && $user->hasStorePermission($user->getActiveStore(), 'employees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'employees.manage');
    }

    /** Approving/cancelling an advance request is an employees.manage action; PAYING one additionally needs finance.manage_payroll (real cash), checked in the controller. */
    public function update(User $user, EmployeeAdvance $advance): bool
    {
        return $this->inActiveOrganization($user, $advance)
            && $user->hasStorePermission($user->getActiveStore(), 'employees.manage');
    }
}
