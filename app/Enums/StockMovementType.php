<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case In         = 'in';
    case Out        = 'out';
    case Adjustment = 'adjustment';
    case Return     = 'return';
    case Damage     = 'damage';

    public function label(): string
    {
        return match ($this) {
            self::In         => 'Stock In',
            self::Out        => 'Stock Out',
            self::Adjustment => 'Adjustment',
            self::Return     => 'Return',
            self::Damage     => 'Damage / Loss',
        };
    }
}
