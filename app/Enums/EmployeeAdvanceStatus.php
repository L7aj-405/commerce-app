<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeAdvanceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Deducted = 'deducted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Paid => 'Paid',
            self::Deducted => 'Deducted (settled via payroll)',
            self::Cancelled => 'Cancelled',
        };
    }
}
