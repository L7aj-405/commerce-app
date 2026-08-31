<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceTransactionType: string
{
    case SaleCreated = 'sale_created';
    case PaymentCollected = 'payment_collected';
    case CodReceivableCreated = 'cod_receivable_created';
    case CodCollected = 'cod_collected';
    case ExpensePaid = 'expense_paid';
    case ExpenseUnpaidRecorded = 'expense_unpaid_recorded';
    case ExpensePaymentReversed = 'expense_payment_reversed';
    case RefundPaid = 'refund_paid';
    case ReturnAdjustment = 'return_adjustment';
    case BankFee = 'bank_fee';
    case ManualAdjustment = 'manual_adjustment';

    // --- COD settlement workflows (external carrier settlement / internal courier deposit) ---
    case CodSettledExternal = 'cod_settled_external';
    case CodSettlementReceived = 'cod_settlement_received';
    case CodClearedByCourier = 'cod_cleared_by_courier';
    case CodCourierVariance = 'cod_courier_variance';
    /** Informational only — the carrier fee this settlement's cash-in amount already nets out. Never moves cash on its own, see FinanceCodSettlementService::settle(). */
    case CodSettlementFeeIncurred = 'cod_settlement_fee_incurred';
    /** Informational only — actual_received_amount vs expected_net_amount, for a provider-period settlement. Mirrors CodCourierVariance. */
    case CodSettlementVariance = 'cod_settlement_variance';

    public function label(): string
    {
        return match ($this) {
            self::SaleCreated => 'Sale created',
            self::PaymentCollected => 'Payment collected',
            self::CodReceivableCreated => 'COD receivable created',
            self::CodCollected => 'COD collected',
            self::ExpensePaid => 'Expense paid',
            self::ExpenseUnpaidRecorded => 'Expense recorded (unpaid)',
            self::ExpensePaymentReversed => 'Expense payment reversed',
            self::RefundPaid => 'Refund paid',
            self::ReturnAdjustment => 'Return adjustment',
            self::BankFee => 'Bank fee',
            self::ManualAdjustment => 'Manual adjustment',
            self::CodSettledExternal => 'COD settled (external carrier)',
            self::CodSettlementReceived => 'COD settlement received (bank)',
            self::CodClearedByCourier => 'COD cleared (courier deposit)',
            self::CodCourierVariance => 'Courier cash variance',
            self::CodSettlementFeeIncurred => 'Carrier delivery fee incurred',
            self::CodSettlementVariance => 'External settlement variance',
        };
    }

    public function defaultDirection(): FinanceTransactionDirection
    {
        return match ($this) {
            self::PaymentCollected, self::CodCollected, self::CodSettlementReceived, self::ExpensePaymentReversed => FinanceTransactionDirection::In,
            self::ExpensePaid, self::RefundPaid, self::BankFee => FinanceTransactionDirection::Out,
            self::SaleCreated, self::CodReceivableCreated, self::ExpenseUnpaidRecorded,
            self::ReturnAdjustment, self::ManualAdjustment,
            self::CodSettledExternal, self::CodClearedByCourier, self::CodCourierVariance,
            self::CodSettlementFeeIncurred, self::CodSettlementVariance => FinanceTransactionDirection::Neutral,
        };
    }

    /**
     * Transaction types that mark a COD order's receivable as CLOSED (no
     * longer pending collection) — used by
     * FinanceOrderTransactionService::pendingCodOrderIds() to exclude an
     * order regardless of which workflow closed it: the ad-hoc single-order
     * "mark collected" action, an external carrier settlement, or an
     * internal courier cash deposit.
     *
     * @return array<int, string>
     */
    public static function codClosingTypes(): array
    {
        return [self::CodCollected->value, self::CodSettledExternal->value, self::CodClearedByCourier->value];
    }
}
