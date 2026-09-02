<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PayrollItem;
use App\Models\User;

class PayrollItemPolicy
{
    private function inActiveOrganization(User $user, PayrollItem $item): bool
    {
        return $item->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function view(User $user, PayrollItem $item): bool
    {
        return $this->inActiveOrganization($user, $item)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function update(User $user, PayrollItem $item): bool
    {
        return $this->inActiveOrganization($user, $item)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_payroll');
    }
}
