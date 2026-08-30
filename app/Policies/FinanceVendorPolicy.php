<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceVendor;
use App\Models\User;

class FinanceVendorPolicy
{
    private function inActiveOrganization(User $user, FinanceVendor $vendor): bool
    {
        return $vendor->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceVendor $vendor): bool
    {
        return $this->inActiveOrganization($user, $vendor)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_vendors');
    }

    public function update(User $user, FinanceVendor $vendor): bool
    {
        return $this->inActiveOrganization($user, $vendor)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_vendors');
    }

    public function delete(User $user, FinanceVendor $vendor): bool
    {
        return $this->update($user, $vendor) && ! $vendor->isInUse();
    }
}
