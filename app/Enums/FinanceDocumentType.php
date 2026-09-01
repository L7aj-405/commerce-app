<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceDocumentType: string
{
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case PaymentProof = 'payment_proof';
    case FuelTicket = 'fuel_ticket';
    case SupplierInvoice = 'supplier_invoice';
    case InternalVoucher = 'internal_voucher';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Facture',
            self::Receipt => 'Reçu',
            self::PaymentProof => 'Preuve de paiement',
            self::FuelTicket => 'Bon carburant / Mazout',
            self::SupplierInvoice => 'Facture fournisseur',
            self::InternalVoucher => 'Bon de caisse interne (photo)',
            self::Other => 'Autre',
        };
    }

    /**
     * External legal proof — an expense with at least one document of one of
     * these types is FinanceExpenseJustificationStatus::Documented AND
     * fiscal_ready, regardless of what justification_type it was originally
     * created with. See FinanceExpenseService's class docblock.
     */
    public static function officialTypes(): array
    {
        return [self::Invoice->value, self::Receipt->value, self::PaymentProof->value, self::FuelTicket->value, self::SupplierInvoice->value];
    }

    /**
     * Internal justification only — proves who paid/received, why, when, how
     * much, but is NEVER a substitute for external legal proof and NEVER
     * upgrades an expense to Documented/fiscal_ready on its own (see
     * FinanceExpenseService::syncJustificationStatus()). A voucher photo
     * upgrades a bare declaration to InternalOnly, nothing more.
     */
    public static function internalTypes(): array
    {
        return [self::InternalVoucher->value];
    }

    public function isOfficial(): bool
    {
        return in_array($this->value, self::officialTypes(), true);
    }

    public function isInternal(): bool
    {
        return in_array($this->value, self::internalTypes(), true);
    }
}
