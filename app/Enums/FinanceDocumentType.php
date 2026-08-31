<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceDocumentType: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case PaymentProof = 'payment_proof';
    case FuelReceipt = 'fuel_receipt';
    case SupplierInvoice = 'supplier_invoice';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Facture',
            self::Receipt => 'Reçu',
            self::PaymentProof => 'Preuve de paiement',
            self::FuelReceipt => 'Bon carburant / Mazout',
            self::SupplierInvoice => 'Facture fournisseur',
            self::Other => 'Autre',
        };
    }
}
