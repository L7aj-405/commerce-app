<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Invoiceable;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Orders\OrderWorkflowService;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Order extends Model implements Invoiceable
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'store_id',
        'organization_id',
        'platform_connection_id',
        'platform_order_id',
        'order_number',
        'source_type',
        'source_platform',
        'source_store_name',
        'source_store_domain',
        'source_channel_label',
        'imported_at',
        'fulfillment_status',
        'fulfillment_updated_at',
        'assigned_to',
        'assigned_at',
        'status',
        'total',
        'currency',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_city_id',
        'confirmed_shipping_address',
        'items',
        'platform_data',
        'synced_at',
        'notes',
        'confirmation_method',
        'confirmation_tier',
        'confirmation_status',
        'whatsapp_sent_at',
        'whatsapp_message_id',
        'whatsapp_message_sent',
        'ai_generated_message',
        'ai_message_generated',
        'ai_cost',
        'n8n_execution_id',
        'n8n_workflow_steps',
        'customer_reply_raw',
        'customer_reply_parsed',
        'last_intent',
        'last_confidence',
        'whatsapp_interactions',
        'whatsapp_confirmed_at',
        'whatsapp_cancelled_at',
        'confirmation_retry_count',
        'confirmation_last_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                    => OrderStatus::class,
            'fulfillment_status'        => \App\Enums\FulfillmentStatus::class,
            'fulfillment_updated_at'    => 'datetime',
            'assigned_at'               => 'datetime',
            'items'                     => 'array',
            'platform_data'             => 'array',
            'n8n_workflow_steps'        => 'array',
            'customer_reply_parsed'     => 'array',
            'whatsapp_interactions'     => 'array',
            'total'                     => 'decimal:2',
            'ai_cost'                   => 'decimal:4',
            'last_confidence'           => 'decimal:2',
            'ai_generated_message'      => 'boolean',
            'confirmation_retry_count'  => 'integer',
            'synced_at'                 => 'datetime',
            'imported_at'               => 'datetime',
            'whatsapp_sent_at'          => 'datetime',
            'whatsapp_confirmed_at'     => 'datetime',
            'whatsapp_cancelled_at'     => 'datetime',
            'confirmation_last_retry_at'=> 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function platformConnection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class);
    }

    public function shippingCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'shipping_city_id');
    }

    public function inventoryAllocation(): MorphOne
    {
        return $this->morphOne(InventoryAllocation::class, 'source');
    }

    /** The most recent external delivery-provider shipment (e.g. Ozon), if any. */
    public function shipment(): MorphOne
    {
        return $this->morphOne(Shipment::class, 'shippable')->latestOfMany();
    }

    /** The most recent internal dispatch-board bookkeeping record (courier or in-house agent), if any. */
    public function orderShipment(): MorphOne
    {
        return $this->morphOne(OrderShipment::class, 'shippable')->latestOfMany();
    }

    public function customerInteractions(): HasMany
    {
        return $this->hasMany(CustomerInteraction::class);
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
        return (string) $this->order_number;
    }

    public function invoiceCustomer(): array
    {
        return [
            'name'    => $this->customer_name ?: 'Customer',
            'email'   => $this->customer_email,
            'phone'   => $this->customer_phone,
            'address' => null,
        ];
    }

    public function invoiceTotals(): array
    {
        // Online orders carry a single total; tax/discount aren't broken out.
        $total = (float) $this->total;

        return [
            'subtotal'        => $total,
            'tax_amount'      => 0.0,
            'discount_amount' => 0.0,
            'total_amount'    => $total,
        ];
    }

    public function invoiceLineItems(): array
    {
        $items = is_array($this->items) ? $this->items : [];

        return array_map(function (array $item): array {
            $qty   = (float) ($item['quantity'] ?? $item['qty'] ?? 1);
            $price = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
            $line  = (float) ($item['line_total'] ?? $item['total'] ?? $qty * $price);

            return [
                'product_id'      => $item['product_id'] ?? null,
                'description'     => (string) ($item['name'] ?? $item['title'] ?? 'Item'),
                'sku'             => $item['sku'] ?? null,
                'quantity'        => $qty,
                'unit_price'      => $price,
                'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                'tax_amount'      => (float) ($item['tax_amount'] ?? 0),
                'line_total'      => $line,
            ];
        }, $items);
    }

    public function invoicePaymentMethod(): string
    {
        return 'cash';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('order')
            ->logOnly(['status', 'total', 'confirmation_status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Confirmed);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Cancelled);
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeNeedsWhatsappConfirmation(Builder $query): Builder
    {
        return $query
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('customer_phone')
            ->whereNull('whatsapp_sent_at');
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === OrderStatus::Confirmed;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }

    public function needsConfirmation(): bool
    {
        return $this->isPending() && $this->customer_phone !== null;
    }

    public function canSendWhatsApp(): bool
    {
        return $this->isPending()
            && filled($this->customer_phone)
            && $this->whatsapp_sent_at === null;
    }

    /**
     * Confirm the order the same way the confirmation desk would.
     *
     * Routed through the workflow service so a customer replying YES on WhatsApp
     * lands the order in the fulfillment lane — commits stock, projects `status`,
     * writes the audit entry — instead of only flipping the commercial column.
     * The confirmation queue then drains itself for every customer who answers,
     * leaving staff only the non-responders.
     */
    public function markAsConfirmed(): void
    {
        $current = $this->fulfillment_status ?? FulfillmentStatus::Pending;

        if ($current->canTransitionTo(FulfillmentStatus::Confirmed)) {
            app(OrderWorkflowService::class)
                ->transition($this, FulfillmentStatus::Confirmed, reason: 'whatsapp_reply');
        }

        // Stamped regardless: an order already moved on by staff should still
        // record that the customer answered.
        $this->update(['whatsapp_confirmed_at' => now()]);
    }

    public function markAsCancelled(): void
    {
        $current = $this->fulfillment_status ?? FulfillmentStatus::Pending;

        if ($current->canTransitionTo(FulfillmentStatus::Cancelled)) {
            app(OrderWorkflowService::class)
                ->transition($this, FulfillmentStatus::Cancelled, reason: 'whatsapp_reply');
        }

        $this->update(['whatsapp_cancelled_at' => now()]);
    }
}
