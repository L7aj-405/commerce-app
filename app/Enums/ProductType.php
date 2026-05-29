<?php

namespace App\Enums;

enum ProductType: string
{
    case SIMPLE = 'simple';
    case VARIABLE = 'variable';

    public function label(): string
    {
        return match($this) {
            self::SIMPLE => 'Simple Product',
            self::VARIABLE => 'Variable Product (with variants)',
        };
    }
}