<?php

declare(strict_types=1);

namespace App\Enums;

enum StoreRole: string
{
    case Owner  = 'owner';
    case Admin  = 'admin';
    case Staff  = 'staff';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner  => 'Owner',
            self::Admin  => 'Admin',
            self::Staff  => 'Staff',
            self::Viewer => 'Viewer',
        };
    }

    public function permissions(): array
    {
        return match ($this) {
            self::Owner  => ['*'],
            self::Admin  => ['read', 'create', 'update', 'delete'],
            self::Staff  => ['read', 'create', 'update'],
            self::Viewer => ['read'],
        };
    }
}
