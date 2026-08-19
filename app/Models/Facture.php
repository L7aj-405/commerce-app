<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Facture extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use LogsActivity;

    protected $keyType = 'string';
    public $incrementing = false;

    // --- Invoice state machine -------------------------------------------------
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ISSUED    = 'issued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_PAID      = 'paid';
    public const STATUS_OVERDUE   = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_VOID      = 'void';

    /** Statuses at/after which the invoice is legally finalized and immutable. */
    public const FINALIZED_STATUSES = [self::STATUS_ISSUED, self::STATUS_SENT, self::STATUS_PAID];

    protected $fillable = [
        'store_id',
        'pos_order_id',
        'invoiceable_type',
        'invoiceable_id',
        'invoice_number',
        'status',
        'payment_status',
        'issued_at',
        'issued_by',
        'locked_at',
        'voided_at',
        'void_reason',
        'invoice_date',
        'due_date',
        'sent_at',
        'paid_at',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'amount_paid',
        'payment_method',
        'pdf_path',
        'notes',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'due_date'        => 'date',
        'issued_at'       => 'datetime',
        'locked_at'       => 'datetime',
        'voided_at'       => 'datetime',
        'sent_at'         => 'datetime',
        'paid_at'         => 'datetime',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'amount_paid'     => 'decimal:2',
    ];

    protected $appends = [
        'amount_remaining',
        'is_overdue',
    ];

    // --- Relationships ---------------------------------------------------------

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The order this invoice was generated from (PosOrder or Order). */
    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(FactureItem::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @deprecated use invoiceable(); kept for backward compatibility. */
    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    // --- State machine ---------------------------------------------------------

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, self::FINALIZED_STATUSES, true);
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /** Once locked, only holders of `invoices.amend` may change it (see FacturePolicy). */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    // --- Derived attributes ----------------------------------------------------

    public function getAmountRemainingAttribute(): float
    {
        return round(((float) $this->total_amount) - ((float) $this->amount_paid), 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->due_date === null) {
            return false;
        }

        return $this->due_date->isPast() && $this->payment_status !== 'paid';
    }

    // --- Audit trail -----------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('invoice')
            ->logOnly([
                'status', 'payment_status', 'total_amount', 'amount_paid',
                'due_date', 'customer_name', 'customer_email', 'locked_at', 'voided_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event): string => "invoice {$event}");
    }
}
