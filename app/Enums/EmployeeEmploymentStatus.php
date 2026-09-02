<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeEmploymentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
            self::Left => 'Left',
        };
    }
}
