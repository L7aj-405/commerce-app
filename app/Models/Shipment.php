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
 * The rich, provider-specific shipment record (Ozon first). Separate from
 * order_shipments (the internal dispatch-board bookkeeping table) on
 * purpose — see the migration docblock. `order_shipment_id` links the two.
 */
class Shipment extends Model
{
    use BelongsToTenant, HasUlids;

    // Normalized statuses, shared across every provider.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CREATED = 'created';
    public const STATUS_SENT_TO_CARRIER = 'sent_to_carrier';
    public const STATUS_AWAITING_PICKUP = 'awaiting_pickup';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED_ATTEMPT = 'failed_attempt';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * add-parcel returned HTTP 200 + a tracking number, but a follow-up
     * parcel-info/tracking call could not confirm that number is real on
     * Ozon's side (observed: Ozon's own dashboard search cannot find a
     * tracking number it just returned). Deliberately NOT in
     * ACTIVE_STATUSES/TERMINAL_STATUSES — it is neither "in flight" nor a
     * dead end; it requires an explicit "Retry verification" action before
     * this project will treat the order as handed to a carrier.
     */
    public const STATUS_PROVIDER_UNVERIFIED = 'provider_unverified';

    /** Statuses that should still be polled for tracking updates. */
    public const ACTIVE_STATUSES = [
        self::STATUS_CREATED,
        self::STATUS_SENT_TO_CARRIER,
        self::STATUS_AWAITING_PICKUP,
        self::STATUS_PICKED_UP,
        self::STATUS_IN_TRANSIT,
        self::STATUS_OUT_FOR_DELIVERY,
        self::STATUS_FAILED_ATTEMPT,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_DELIVERED,
        self::STATUS_RETURNED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUSED,
    ];

    protected $fillable = [
        'store_id', 'organization_id',
        'shippable_type', 'shippable_id',
        'delivery_connection_id', 'provider_code',
        'provider_shipment_id', 'tracking_number', 'delivery_note_ref', 'order_shipment_id',
        'status', 'provider_status',
        'receiver_name', 'phone', 'city_id', 'city_name', 'address',
        'cod_amount', 'delivery_fee', 'raw_payload',
        'sent_at', 'delivered_at', 'returned_at', 'cancelled_at',
        // Fee snapshot — see FinanceDeliveryProviderFeeCalculator::snapshotForShipment().
        // Computed once, never silently recalculated.
        'expected_delivery_fee', 'expected_return_fee', 'expected_refusal_fee',
        'expected_cod_fee', 'expected_total_carrier_fee', 'fee_source', 'fee_calculated_at', 'fee_metadata',
        'manual_fee_override', 'manual_fee_override_reason', 'manual_fee_overridden_by', 'manual_fee_overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'cod_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expected_delivery_fee' => 'decimal:2',
            'expected_return_fee' => 'decimal:2',
            'expected_refusal_fee' => 'decimal:2',
            'expected_cod_fee' => 'decimal:2',
            'expected_total_carrier_fee' => 'decimal:2',
            'fee_calculated_at' => 'datetime',
            'fee_metadata' => 'array',
            'manual_fee_override' => 'decimal:2',
            'manual_fee_overridden_at' => 'datetime',
        ];
    }

    /** The fee amount settlement math should actually use — a manual override always wins over the computed snapshot. */
    public function effectiveCarrierFee(): ?float
    {
        return $this->manual_fee_override !== null
            ? (float) $this->manual_fee_override
            : ($this->expected_total_carrier_fee !== null ? (float) $this->expected_total_carrier_fee : null);
    }

    public function hasFeeSnapshot(): bool
    {
        return $this->fee_calculated_at !== null;
    }

    /** @return array<int, string> */
    public static function normalizedStatuses(): array
    {
        return [
            self::STATUS_DRAFT, self::STATUS_CREATED, self::STATUS_SENT_TO_CARRIER,
            self::STATUS_AWAITING_PICKUP, self::STATUS_PICKED_UP, self::STATUS_IN_TRANSIT,
            self::STATUS_OUT_FOR_DELIVERY, self::STATUS_DELIVERED, self::STATUS_FAILED_ATTEMPT,
            self::STATUS_RETURNED, self::STATUS_REFUSED, self::STATUS_CANCELLED, self::STATUS_UNKNOWN,
            self::STATUS_PROVIDER_UNVERIFIED,
        ];
    }

    public function shippable(): MorphTo
    {
        return $this->morphTo();
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DeliveryConnection::class, 'delivery_connection_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'provider_code', 'code');
    }

    public function orderShipment(): BelongsTo
    {
        return $this->belongsTo(OrderShipment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->latest('created_at');
    }

    public function providerCity(): BelongsTo
    {
        return $this->belongsTo(DeliveryProviderCity::class, 'city_id');
    }

    public function manualFeeOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_fee_overridden_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isOzon(): bool
    {
        return $this->provider_code === DeliveryProvider::OZON;
    }

    public function isSendit(): bool
    {
        return $this->provider_code === DeliveryProvider::SENDIT;
    }
}
