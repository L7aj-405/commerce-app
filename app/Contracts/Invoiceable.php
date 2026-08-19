<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Anything that can be turned into an invoice (Facture). Implemented by both
 * PosOrder (instant sales) and Order (deferred/online), so InvoiceService only
 * ever depends on this contract — never on a concrete order type.
 */
interface Invoiceable
{
    public function invoiceStoreId(): string;

    /** Human-facing source reference, e.g. receipt or order number. */
    public function invoiceReference(): string;

    /** @return array{name: string, email: ?string, phone: ?string, address: ?string} */
    public function invoiceCustomer(): array;

    /** @return array{subtotal: float, tax_amount: float, discount_amount: float, total_amount: float} */
    public function invoiceTotals(): array;

    /**
     * @return array<int, array{product_id: ?string, description: string, sku: ?string, quantity: float, unit_price: float, discount_amount: float, tax_amount: float, line_total: float}>
     */
    public function invoiceLineItems(): array;

    public function invoicePaymentMethod(): string;

    /** The generated invoice, if any (polymorphic). */
    public function invoice(): MorphOne;
}
