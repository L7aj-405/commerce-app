<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical order fulfillment workflow, shared by POS and online orders.
 *
 * Step 6 splits the old broad warehouse state into explicit, operationally
 * useful states:
 *   - waiting_for_stock: confirmed, but transfer/replenishment is still needed
 *   - ready_for_picking: stock is reserved and the warehouse can start
 *   - picking / packing: physical work inside the warehouse
 *   - ready_for_delivery: parcel has left warehouse control and logistics owns it
 *
 * `confirmed` and `in_progress` remain for backwards compatibility with older
 * rows/tests/UI actions. New confirmation requests still submit `confirmed`, but
 * OrderWorkflowService resolves the final persisted state from inventory
 * allocation: ready_for_picking or waiting_for_stock.
 */
enum FulfillmentStatus: string
{
    case Pending          = 'pending';
    case Confirmed        = 'confirmed';        // legacy/request target
    case WaitingForStock  = 'waiting_for_stock';
    case ReadyForPicking  = 'ready_for_picking';
    case Picking          = 'picking';
    case Packing          = 'packing';
    case InProgress       = 'in_progress';       // legacy combined pick/pack
    case ReadyForDelivery = 'ready_for_delivery';
    case Delivered        = 'delivered';
    case Completed        = 'completed';
    case Cancelled        = 'cancelled';
    case Returned         = 'returned';
    case UnderInspection  = 'under_inspection';
    case ReturnCompleted  = 'return_completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending          => 'Pending confirmation',
            self::Confirmed        => 'Confirmed',
            self::WaitingForStock  => 'Waiting for stock',
            self::ReadyForPicking  => 'Ready for picking',
            self::Picking          => 'Picking',
            self::Packing          => 'Packing',
            self::InProgress       => 'Processing',
            self::ReadyForDelivery => 'Ready for delivery',
            self::Delivered        => 'Delivered',
            self::Completed        => 'Completed',
            self::Cancelled        => 'Cancelled',
            self::Returned         => 'Returned — awaiting inspection',
            self::UnderInspection  => 'Under inspection',
            self::ReturnCompleted  => 'Return closed',
        };
    }

    /** @return array<int, self> statuses this one may move to */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],

            // Legacy Confirmed rows are treated as warehouse-ready rows.
            self::Confirmed       => [self::ReadyForPicking, self::Picking, self::InProgress, self::Cancelled],
            self::WaitingForStock => [self::ReadyForPicking, self::Cancelled],
            self::ReadyForPicking => [self::Picking, self::Cancelled],
            self::Picking         => [self::Packing, self::Cancelled],
            self::Packing         => [self::ReadyForDelivery, self::Cancelled],

            // Legacy combined state: existing users can still push it straight to dispatch.
            self::InProgress       => [self::ReadyForDelivery, self::Cancelled],
            self::ReadyForDelivery => [self::Delivered, self::Returned, self::Cancelled],
            self::Delivered        => [self::Completed, self::Returned],
            self::Completed        => [self::Returned],
            self::Returned         => [self::UnderInspection],
            self::UnderInspection  => [self::ReturnCompleted],
            self::Cancelled, self::ReturnCompleted => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->transitions() === [];
    }

    /** Action verb for the button that moves an order into $this. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Pending          => 'Reopen',
            self::Confirmed        => 'Confirm',
            self::WaitingForStock  => 'Wait for stock',
            self::ReadyForPicking  => 'Move to picking',
            self::Picking          => 'Start picking',
            self::Packing          => 'Move to packing',
            self::InProgress       => 'Start processing',
            self::ReadyForDelivery => 'Mark ready',
            self::Delivered        => 'Mark delivered',
            self::Completed        => 'Complete',
            self::Cancelled        => 'Cancel',
            self::Returned         => 'Mark returned',
            self::UnderInspection  => 'Start inspection',
            self::ReturnCompleted  => 'Close return',
        };
    }

    /**
     * @return 'confirmation'|'fulfillment'|'delivery'|'closed'|'returns'
     */
    public function phase(): string
    {
        return match ($this) {
            self::Pending => 'confirmation',
            self::Confirmed,
            self::WaitingForStock,
            self::ReadyForPicking,
            self::Picking,
            self::Packing,
            self::InProgress => 'fulfillment',
            self::ReadyForDelivery, self::Delivered => 'delivery',
            self::Completed, self::Cancelled => 'closed',
            self::Returned, self::UnderInspection, self::ReturnCompleted => 'returns',
        };
    }

    public function isReturnFlow(): bool
    {
        return $this->phase() === 'returns';
    }

    public function requiresReason(): bool
    {
        return match ($this) {
            self::Cancelled, self::Returned => true,
            default => false,
        };
    }

    public function permission(?self $from = null): string
    {
        if ($this === self::Cancelled) {
            return match (($from ?? self::Pending)->phase()) {
                'confirmation' => 'orders.confirm',
                'delivery'     => 'orders.dispatch',
                default        => 'orders.fulfil',
            };
        }

        return match ($this) {
            self::Confirmed => 'orders.confirm',
            self::Delivered, self::Completed => 'orders.dispatch',
            self::Returned => 'orders.return',
            self::UnderInspection, self::ReturnCompleted => 'orders.inspect',
            default => 'orders.fulfil',
        };
    }

    public static function default(): self
    {
        return self::Pending;
    }
}
