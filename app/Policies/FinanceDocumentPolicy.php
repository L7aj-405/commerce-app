<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FinanceDocument;
use App\Models\User;

/**
 * Upload is authorized against the parent documentable's own policy (e.g.
 * FinanceExpensePolicy::update — finance.manage_expenses), not here, so a
 * document is never a way around the finance rules that already gate its
 * parent. This policy only covers the document row itself once it exists.
 */
class FinanceDocumentPolicy
{
    private function inActiveOrganization(User $user, FinanceDocument $document): bool
    {
        return $document->organization_id === $user->getActiveStore()?->organization_id;
    }

    public function view(User $user, FinanceDocument $document): bool
    {
        return $this->inActiveOrganization($user, $document)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.view');
    }

    public function delete(User $user, FinanceDocument $document): bool
    {
        return $this->inActiveOrganization($user, $document)
            && $user->hasStorePermission($user->getActiveStore(), 'finance.manage_expenses');
    }
}
