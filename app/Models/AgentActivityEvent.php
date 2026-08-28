<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only agent/operational activity ledger. See the creating migration
 * for the full rationale. Never edited after insert — no `updated_at`.
 */
class AgentActivityEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    // Confirmation desk.
    public const CONFIRMATION_CLAIMED = 'confirmation.claimed';

    public const CONFIRMATION_CONFIRMED = 'confirmation.confirmed';

    public const CONFIRMATION_CANCELLED = 'confirmation.cancelled';

    /** Documented limitation: no current UI action emits this — see plan notes. */
    public const CONFIRMATION_UNREACHABLE = 'confirmation.unreachable';

    public const CONFIRMATION_ADDRESS_UPDATED = 'confirmation.address_updated';

    // Fulfillment (pick/pack).
    public const FULFILLMENT_PICKED = 'fulfillment.picked';

    public const FULFILLMENT_PACKED = 'fulfillment.packed';

    /** Documented limitation: no current UI action emits this — see plan notes. */
    public const FULFILLMENT_ERROR_REPORTED = 'fulfillment.error_reported';

    // Delivery.
    public const DELIVERY_ASSIGNED = 'delivery.assigned';

    public const DELIVERY_DELIVERED = 'delivery.delivered';

    public const DELIVERY_FAILED = 'delivery.failed';

    public const DELIVERY_UNREACHABLE = 'delivery.unreachable';

    // Returns.
    public const RETURN_INSPECTED = 'return.inspected';

    // Inventory.
    public const INVENTORY_ADJUSTED = 'inventory.adjusted';

    public const STOCK_TRANSFER_RECEIVED = 'stock.transfer.received';

    protected $fillable = [
        'organization_id', 'store_id', 'user_id', 'role_context',
        'event_type', 'subject_type', 'subject_id',
        'order_id', 'order_item_id', 'source_module',
        'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return $query->whereIn('event_type', (array) $types);
    }

    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }
}
