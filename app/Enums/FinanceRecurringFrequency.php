<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum FinanceRecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }

    /** Advance a due date to the next occurrence for this frequency. */
    public function advance(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $date->addWeek(),
            self::Monthly => $date->addMonthNoOverflow(),
            self::Quarterly => $date->addMonthsNoOverflow(3),
            self::Yearly => $date->addYearNoOverflow(),
        };
    }
}
