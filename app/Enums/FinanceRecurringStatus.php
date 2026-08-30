<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceRecurringStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
        };
    }
}
