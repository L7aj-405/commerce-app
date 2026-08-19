<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Facture;
use App\Models\User;

/**
 * Tenancy + permission + immutability rules for invoices.
 *
 * Multi-tenancy: every check first confirms the invoice belongs to the user's
 * active store, so a permission in store A can never touch store B's data.
 *
 * Immutability: a finalized (locked) invoice can only be changed by a user who
 * holds the elevated `invoices.amend` permission — this is the "restrict edits
 * after finalize; further edits require higher-level permission" requirement.
 */
class FacturePolicy
{
    private function inActiveStore(User $user, Facture $facture): bool
    {
        return $facture->store_id === $user->getActiveStore()?->id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'factures.view');
    }

    public function view(User $user, Facture $facture): bool
    {
        return $this->inActiveStore($user, $facture)
            && $user->hasStorePermission($user->getActiveStore(), 'factures.view');
    }

    /**
     * Editing an invoice. A locked (finalized) invoice needs `invoices.amend`;
     * an unlocked draft only needs `invoices.issue`.
     */
    public function update(User $user, Facture $facture): bool
    {
        if (! $this->inActiveStore($user, $facture) || $facture->isVoid()) {
            return false;
        }

        $store = $user->getActiveStore();

        return $facture->isLocked()
            ? $user->hasStorePermission($store, 'invoices.amend')
            : $user->hasStorePermission($store, 'invoices.issue');
    }

    public function amend(User $user, Facture $facture): bool
    {
        return $this->update($user, $facture);
    }

    public function void(User $user, Facture $facture): bool
    {
        return $this->inActiveStore($user, $facture)
            && ! $facture->isVoid()
            && $user->hasStorePermission($user->getActiveStore(), 'invoices.void');
    }
}
