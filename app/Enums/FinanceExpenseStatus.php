<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceExpenseStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Unpaid => 'Unpaid',
            self::Cancelled => 'Cancelled',
        };
    }
}
