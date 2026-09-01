<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the user declared at creation time about proof of this expense — the
 * historical record of which path was chosen. See FinanceExpenseJustificationStatus
 * for the LIVE-derived counterpart (which reacts to documents attached later).
 */
enum FinanceExpenseJustificationType: string
{
    case OfficialDocument = 'official_document';
    case InternalCashVoucher = 'internal_cash_voucher';
    case NoInvoice = 'no_invoice';

    public function label(): string
    {
        return match ($this) {
            self::OfficialDocument => 'Official invoice/receipt',
            self::InternalCashVoucher => 'Internal cash voucher',
            self::NoInvoice => 'No official invoice',
        };
    }

    public function requiresJustification(): bool
    {
        return $this !== self::OfficialDocument;
    }
}
