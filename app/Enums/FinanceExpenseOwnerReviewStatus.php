<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Owner/admin review of a non-official-document expense's justification.
 * Only ever set on an expense whose justification_type isn't
 * OfficialDocument (see FinanceExpenseService::create()) — an
 * official-document expense has no review workflow and keeps this null.
 */
enum FinanceExpenseOwnerReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsMoreInfo = 'needs_more_info';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending owner review',
            self::Approved => 'Approved internally',
            self::Rejected => 'Rejected',
            self::NeedsMoreInfo => 'Needs more info',
        };
    }
}
