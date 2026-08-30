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
    case DeliveredCollectable = 'delivered_collectable';
    case Settled = 'settled';
    case CancelledOrReturned = 'cancelled_or_returned';

    /** Short label for a status chip. */
    public function label(): string
    {
        return match ($this) {
            self::NotDelivered => 'Not delivered yet',
            self::WithInternalCourier => 'With courier',
            self::WithExternalCarrier => 'With carrier',
            self::DeliveredCollectable => 'Delivered — ready to collect',
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
            self::Settled => 'This COD receivable has already been collected or settled.',
            self::CancelledOrReturned => 'This order was cancelled or returned and its COD receivable is no longer collectable.',
        };
    }

    public function isCollectable(): bool
    {
        return $this === self::DeliveredCollectable;
    }
}
