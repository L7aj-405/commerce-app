<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a POS customer is a walk-in individual or a registered company.
 * This is the single source of truth for the "dynamic print" rule: an
 * individual gets an 80mm thermal receipt, a company gets an A4 invoice.
 */
enum CustomerType: string
{
    case Individual = 'individual';
    case Company    = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company    => 'Company',
        };
    }

    /** Print format this customer type should produce: 'thermal' | 'a4'. */
    public function documentFormat(): string
    {
        return $this === self::Company ? 'a4' : 'thermal';
    }

    public function isCompany(): bool
    {
        return $this === self::Company;
    }
}
