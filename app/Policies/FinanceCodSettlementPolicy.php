<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceCodSettlement;
use App\Models\User;

class FinanceCodSettlementPolicy
{
    private function inActiveOrganization(User $user, FinanceCodSettlement $settlement): bool
    {
        return $settlement->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function view(User $user, FinanceCodSettlement $settlement): bool
    {
        return $this->inActiveOrganization($user, $settlement)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cod_settlements');
    }

    public function update(User $user, FinanceCodSettlement $settlement): bool
    {
        return $this->inActiveOrganization($user, $settlement)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_cod_settlements');
    }
}
