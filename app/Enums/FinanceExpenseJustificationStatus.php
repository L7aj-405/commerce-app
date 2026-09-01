<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Live snapshot of how well-documented an expense currently is — recomputed
 * by FinanceExpenseService::syncJustificationStatus() on create, update, and
 * whenever a document is attached to or removed from the expense. Unlike
 * FinanceExpenseJustificationType (what the user originally declared), this
 * reacts to reality: attaching an official invoice later upgrades a
 * `no_invoice` expense to Documented without changing its declared type.
 */
enum FinanceExpenseJustificationStatus: string
{
    case Documented = 'documented';
    case InternalOnly = 'internal_only';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Documented => 'Documented',
            self::InternalOnly => 'Internal voucher only',
            self::NeedsReview => 'Needs owner review',
        };
    }
}
