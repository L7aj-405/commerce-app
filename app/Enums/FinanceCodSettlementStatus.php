<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceCodSettlementStatus: string
{
    case Draft = 'draft';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Settled => 'Settled',
            self::Cancelled => 'Cancelled',
        };
    }
}
