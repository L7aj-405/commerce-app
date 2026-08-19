<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Invoiceable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PosOrder extends Model implements Invoiceable
{
    use BelongsToTenant;
    use HasUlids;
    use LogsActivity;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * Use the human-readable receipt number in URLs instead of the ULID.
     */
    public function getRouteKeyName(): string
    {
        return 'receipt_number';
    }

    /**
     * Resolve by receipt_number (the route key) but still accept the ULID id,
     * so older id-based links keep working. Tenant scoping (BelongsToTenant)
     * still applies, so a receipt number only resolves within the active store.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->orWhere($this->getKeyName(), $value)
            ->first();
    }

    protected $fillable = [
        'store_id',
        'pos_session_id',
        'cashier_id',
        'receipt_number',
        'status',
        'fulfillment_status',
        'fulfillment_updated_at',
        'assigned_to',
        'assigned_at',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'payment_method',
        'amount_paid',
        'change_amount',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_type',
        'company_name',
        'tax_id',
        'notes',
        'delivery_address',
        'shipping_city_id',
        'delivery_fee',
        'invoice_path',
        'receipt_path',
    ];

    protected $casts = [
        'subtotal'               => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'delivery_fee'           => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'amount_paid'            => 'decimal:2',
        'change_amount'          => 'decimal:2',
        'fulfillment_status'     => \App\Enums\FulfillmentStatus::class,
        'fulfillment_updated_at' => 'datetime',
        'assigned_at'            => 'datetime',
        'customer_type'          => \App\Enums\CustomerType::class,
    ];

    /** True when this order is billed to a company (drives A4 invoice output). */
    public function isCompany(): bool
    {
        return $this->customer_type === \App\Enums\CustomerType::Company;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shippingCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'shipping_city_id');
    }

    public function inventoryAllocation(): MorphOne
    {
        return $this->morphOne(InventoryAllocation::class, 'source');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosOrderItem::class);
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Facture::class, 'invoiceable');
    }

    // --- Invoiceable contract --------------------------------------------------

    public function invoiceStoreId(): string
    {
        return (string) $this->store_id;
    }

    public function invoiceReference(): string
    {
        return (string) $this->receipt_number;
    }

    public function invoiceCustomer(): array
    {
        return [
            'name'    => $this->customer_name ?: 'Walk-in customer',
            'email'   => $this->customer_email,
            'phone'   => $this->customer_phone,
            'address' => null,
        ];
    }

    public function invoiceTotals(): array
    {
        return [
            'subtotal'        => (float) $this->subtotal,
            'tax_amount'      => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total_amount'    => (float) $this->total_amount,
        ];
    }

    public function invoiceLineItems(): array
    {
        return $this->items->map(fn (PosOrderItem $item): array => [
            'product_id'      => $item->product_id,
            'description'     => $item->product_name ?: 'Item',
            'sku'             => $item->product_sku,
            'quantity'        => (float) $item->quantity,
            'unit_price'      => (float) $item->unit_price,
            'discount_amount' => (float) $item->discount_amount,
            'tax_amount'      => (float) $item->tax_amount,
            'line_total'      => (float) $item->line_total,
        ])->all();
    }

    public function invoicePaymentMethod(): string
    {
        return (string) ($this->payment_method ?: 'cash');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('pos_order')
            ->logOnly(['status', 'total_amount', 'amount_paid', 'payment_method'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
