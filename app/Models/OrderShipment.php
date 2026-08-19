<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The dispatch leg of an order — third-party courier or one of our own agents.
 */
class OrderShipment extends Model
{
    use BelongsToTenant, HasUlids;

    public const CARRIER_COURIER  = 'courier';
    public const CARRIER_INTERNAL = 'internal';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_DELIVERED  = 'delivered';
    public const STATUS_FAILED     = 'failed';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id', 'shippable_type', 'shippable_id', 'reference',
        'carrier_type', 'carrier_name', 'tracking_number', 'tracking_url',
        'agent_id', 'manifest_reference', 'status', 'delivery_address',
        'cod_amount', 'cod_collected',
        'notes', 'failure_reason', 'dispatched_by', 'dispatched_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'delivered_at'  => 'datetime',
            'cod_amount'    => 'decimal:2',
            'cod_collected' => 'decimal:2',
        ];
    }

    /** @return array<int, string> */
    public static function carrierTypes(): array
    {
        return [self::CARRIER_COURIER, self::CARRIER_INTERNAL];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Order (online) or PosOrder. */
    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_DISPATCHED]);
    }

    public function isInternal(): bool
    {
        return $this->carrier_type === self::CARRIER_INTERNAL;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_FAILED], true);
    }

    /** Who is physically carrying it, whichever kind of carrier this is. */
    public function carrierLabel(): string
    {
        return $this->isInternal()
            ? ($this->agent?->name ?? 'Internal agent')
            : ($this->carrier_name ?? 'Courier');
    }
}
