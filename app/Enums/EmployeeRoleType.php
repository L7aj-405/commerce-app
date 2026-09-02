<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeRoleType: string
{
    case ConfirmationAgent = 'confirmation_agent';
    case Picker = 'picker';
    case Packer = 'packer';
    case Cashier = 'cashier';
    case DeliveryAgent = 'delivery_agent';
    case Dispatcher = 'dispatcher';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ConfirmationAgent => 'Confirmation agent',
            self::Picker => 'Picker',
            self::Packer => 'Packer',
            self::Cashier => 'Cashier',
            self::DeliveryAgent => 'Delivery agent',
            self::Dispatcher => 'Dispatcher',
            self::Manager => 'Manager',
            self::Accountant => 'Accountant',
            self::Other => 'Other',
        };
    }
}
