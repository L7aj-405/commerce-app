<?php

declare(strict_types=1);

namespace App\Enums;

enum FinancePaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cheque = 'cheque';
    case CodSettlement = 'cod_settlement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Card => 'Card',
            self::Cheque => 'Cheque',
            self::CodSettlement => 'COD settlement',
            self::Other => 'Other',
        };
    }
}
