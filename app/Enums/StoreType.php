<?php

declare(strict_types=1);

namespace App\Enums;

enum StoreType: string
{
    case Online   = 'online';
    case Physical = 'physical';
    case Hybrid   = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Online   => 'Online',
            self::Physical => 'Physical',
            self::Hybrid   => 'Hybrid',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Online   => '🌐',
            self::Physical => '🏪',
            self::Hybrid   => '🔀',
        };
    }

    public static function default(): self
    {
        return self::Online;
    }
}
