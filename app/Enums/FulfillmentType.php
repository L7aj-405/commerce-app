<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a POS sale is fulfilled at checkout time. This is the single source of
 * truth for the two divergent flows:
 *
 *  - Instant  → customer walks out with the goods now. The order is born
 *               `completed`, stock is committed immediately, a receipt prints.
 *  - Delivery → the order needs gathering / packing / shipping first. Because a
 *               cashier creates it face-to-face, it BYPASSES the confirmation
 *               desk: it is born `confirmed` and drops straight into the packing
 *               queue, unlike an online order which must be confirmed first.
 *
 * Stock is deducted immediately for BOTH types (the sale reserves the goods so
 * they cannot be oversold) — the difference is purely the fulfillment state the
 * order starts in. See initialFulfillmentStatus().
 */
enum FulfillmentType: string
{
    case Instant  = 'instant';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Instant  => 'Instant pickup',
            self::Delivery => 'Delivery / later',
        };
    }

    /**
     * Channel-level `status` the pos_orders row starts in. 'pending_delivery' is
     * what OrderPresenter routes into the delivery lane of the board.
     */
    public function initialStatus(): string
    {
        return $this === self::Delivery ? 'pending_delivery' : 'completed';
    }

    /**
     * Workflow state the order enters the Order Management board with.
     *
     * Delivery starts at Confirmed, not Pending: a POS delivery order is
     * pre-approved by the cashier at the counter, so it skips the confirmation
     * department and lands directly in the packing queue (Confirmed sits in the
     * `fulfillment` phase). Only online orders begin at Pending.
     */
    public function initialFulfillmentStatus(): FulfillmentStatus
    {
        return $this === self::Delivery ? FulfillmentStatus::Confirmed : FulfillmentStatus::Completed;
    }

    public function isDelivery(): bool
    {
        return $this === self::Delivery;
    }
}
