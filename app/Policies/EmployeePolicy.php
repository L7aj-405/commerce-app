<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    private function inActiveOrganization(User $user, Employee $employee): bool
    {
        return $employee->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->inActiveOrganization($user, $employee)
            && $user->hasStorePermission($user->getActiveStore(), 'employees.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->inActiveOrganization($user, $employee)
            && $user->hasStorePermission($user->getActiveStore(), 'employees.manage');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->update($user, $employee);
    }
}
