<?php

declare(strict_types=1);

namespace App\Enums;

enum StoreStatus: string
{
    case Active    = 'active';
    case Inactive  = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Active',
            self::Inactive  => 'Inactive',
            self::Suspended => 'Suspended',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active    => 'green',
            self::Inactive  => 'gray',
            self::Suspended => 'red',
        };
    }

    public static function default(): self
    {
        return self::Active;
    }
}
