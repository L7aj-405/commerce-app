<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed    = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::Failed    => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending   => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            self::Confirmed => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
            self::Cancelled => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
            self::Completed => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            self::Failed    => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending   => '⏳',
            self::Confirmed => '✅',
            self::Cancelled => '❌',
            self::Completed => '🎉',
            self::Failed    => '⚠️',
        };
    }

    public static function default(): self
    {
        return self::Pending;
    }
}
