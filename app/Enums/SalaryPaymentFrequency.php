<?php

declare(strict_types=1);

namespace App\Enums;

enum SalaryPaymentFrequency: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Weekly => 'Weekly',
            self::Biweekly => 'Every 2 weeks',
        };
    }
}
