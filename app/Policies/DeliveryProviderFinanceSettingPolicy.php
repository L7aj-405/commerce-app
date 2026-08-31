<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryProviderFinanceSetting;
use App\Models\User;

/** Reuses the existing finance.view / finance.manage_cod_settlements permissions per the task spec — no new permission catalog entry. */
class DeliveryProviderFinanceSettingPolicy
{
    private function inActiveOrganization(User $user, DeliveryProviderFinanceSetting $setting): bool
    {
        return $setting->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, DeliveryProviderFinanceSetting $setting): bool
    {
        return $this->inActiveOrganization($user, $setting)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function manage(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cod_settlements');
    }

    public function update(User $user, DeliveryProviderFinanceSetting $setting): bool
    {
        return $this->inActiveOrganization($user, $setting)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cod_settlements');
    }
}
