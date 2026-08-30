<?php

declare(strict_types=1);

namespace App\Enums;

/** Which way cash moves. `Neutral` records a fact (a sale happened, a receivable was created) without moving cash. */
enum FinanceTransactionDirection: string
{
    case In = 'in';
    case Out = 'out';
    case Neutral = 'neutral';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Cash in',
            self::Out => 'Cash out',
            self::Neutral => 'Neutral',
        };
    }
}
