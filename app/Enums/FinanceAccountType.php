<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Card = 'card';
    case CodReceivable = 'cod_receivable';
    case DeliveryCompany = 'delivery_company';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank',
            self::Card => 'Card / TPE',
            self::CodReceivable => 'COD Receivable',
            self::DeliveryCompany => 'Delivery Company Balance',
            self::Other => 'Other',
        };
    }
}
