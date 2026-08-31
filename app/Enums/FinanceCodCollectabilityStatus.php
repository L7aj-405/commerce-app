<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a COD order's receivable can actually be acted on right now.
 * Deliberately separate from the receivable's mere EXISTENCE
 * (cod_receivable_created, written the moment the order is confirmed) —
 * see FinanceCodCollectabilityService for the delivery-lifecycle check.
 */
enum FinanceCodCollectabilityStatus: string
{
    case NotDelivered = 'not_delivered';
    case WithInternalCourier = 'with_internal_courier';
    case WithExternalCarrier = 'with_external_carrier';
    /** Delivered, no carrier on file — a genuine manual/direct-pickup collection. The ONLY status the ad-hoc "Mark collected" action is allowed for. */
    case DeliveredCollectable = 'delivered_collectable';
    /** Delivered by an external provider (Ozon/Sendit/...) — closeable only via External Settlements, never the direct action. */
    case DeliveredAwaitingProviderPayout = 'delivered_awaiting_provider_payout';
    /** Delivered by an internal courier/agent — closeable only via Courier Deposits, never the direct action. */
    case DeliveredAwaitingCourierDeposit = 'delivered_awaiting_courier_deposit';
    case Settled = 'settled';
    case CancelledOrReturned = 'cancelled_or_returned';

    /** Short label for a status chip. Carrier-specific wording (e.g. "awaiting Ozon Express payout") is composed by FinanceCodCollectabilityService, which knows the actual carrier name — this is the generic fallback. */
    public function label(): string
    {
        return match ($this) {
            self::NotDelivered => 'Not delivered yet',
            self::WithInternalCourier => 'With courier',
            self::WithExternalCarrier => 'With carrier',
            self::DeliveredCollectable => 'Delivered — ready to collect',
            self::DeliveredAwaitingProviderPayout => 'Ready for external settlement',
            self::DeliveredAwaitingCourierDeposit => 'Delivered — awaiting courier deposit',
            self::Settled => 'Settled',
            self::CancelledOrReturned => 'Cancelled / returned',
        };
    }

    /** Longer helper/tooltip text explaining WHY the action is (or isn't) available. */
    public function reason(): string
    {
        return match ($this) {
            self::NotDelivered => 'This COD order cannot be collected yet because it has not been delivered.',
            self::WithInternalCourier => 'This COD order is with an internal courier and cannot be collected until delivery is confirmed.',
            self::WithExternalCarrier => 'This COD order is with an external carrier and cannot be collected until delivery is confirmed.',
            self::DeliveredCollectable => 'Delivered — this COD order is ready to collect.',
            self::DeliveredAwaitingProviderPayout => 'This COD order is assigned to an external delivery provider. Use External Settlements to reconcile the provider payout.',
            self::DeliveredAwaitingCourierDeposit => 'This COD order is assigned to an internal courier. Use Courier Deposits to record the cash handover.',
            self::Settled => 'This COD receivable has already been collected or settled.',
            self::CancelledOrReturned => 'This order was cancelled or returned and its COD receivable is no longer collectable.',
        };
    }

    /**
     * Eligible for ANY closing workflow — direct collection, external
     * settlement, or courier deposit — i.e. "delivered and not yet closed".
     * Used by FinanceOrderTransactionService::resolveCollectableOrders() to
     * gate BATCH inclusion (settlements/deposits), which stays carrier-
     * agnostic on purpose: a settlement's own carrier_name is free text and
     * has never required a real Shipment/OrderShipment record to exist.
     * See isDirectlyCollectable() for the stricter, carrier-aware gate on
     * the single-order ad-hoc "Mark collected" action.
     */
    public function isCollectable(): bool
    {
        return in_array($this, [self::DeliveredCollectable, self::DeliveredAwaitingProviderPayout, self::DeliveredAwaitingCourierDeposit], true);
    }

    /** True ONLY for a genuine manual/direct-pickup delivery — the sole case the ad-hoc "Mark collected" action may be used for. */
    public function isDirectlyCollectable(): bool
    {
        return $this === self::DeliveredCollectable;
    }
}
