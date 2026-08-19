<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One reverse-logistics event: goods coming back from a single order.
 * The per-line condition lives on OrderReturnItem — this header only tracks
 * where the whole return is in the inspection workflow.
 */
class OrderReturn extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_AWAITING_INSPECTION = 'awaiting_inspection';
    public const STATUS_INSPECTING          = 'inspecting';
    public const STATUS_CLOSED              = 'closed';

    public const REASON_REFUSED           = 'refused';
    public const REASON_DAMAGED_IN_TRANSIT = 'damaged_in_transit';
    public const REASON_WRONG_ITEM        = 'wrong_item';
    public const REASON_CUSTOMER_REMORSE  = 'customer_remorse';
    public const REASON_OTHER             = 'other';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'returnable_type',
        'returnable_id',
        'reference',
        'status',
        'reason',
        'notes',
        'flagged_by',
        'inspected_by',
        'flagged_at',
        'inspected_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'flagged_at'   => 'datetime',
            'inspected_at' => 'datetime',
            'closed_at'    => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public static function reasons(): array
    {
        return [
            self::REASON_REFUSED,
            self::REASON_DAMAGED_IN_TRANSIT,
            self::REASON_WRONG_ITEM,
            self::REASON_CUSTOMER_REMORSE,
            self::REASON_OTHER,
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Order (online) or PosOrder. */
    public function returnable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_AWAITING_INSPECTION, self::STATUS_INSPECTING]);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * A return may only close once every line has a recorded condition. This is
     * the precondition deliberately kept out of FulfillmentStatus::transitions()
     * — the UI renders "Close return" disabled rather than hiding it.
     */
    public function isFullyDispositioned(): bool
    {
        return ! $this->items()->whereNull('condition')->exists();
    }
}
