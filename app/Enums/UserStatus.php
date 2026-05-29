<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Active',
            self::Suspended => 'Suspended',
            self::Banned    => 'Banned',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active    => 'green',
            self::Suspended => 'yellow',
            self::Banned    => 'red',
        };
    }

    public static function default(): self
    {
        return self::Active;
    }
}
