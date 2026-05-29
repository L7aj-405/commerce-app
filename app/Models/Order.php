<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id',
        'platform_connection_id',
        'platform_order_id',
        'order_number',
        'status',
        'total',
        'currency',
        'customer_name',
        'customer_email',
        'customer_phone',
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

    public function customerInteractions(): HasMany
    {
        return $this->hasMany(CustomerInteraction::class);
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

    public function markAsConfirmed(): void
    {
        $this->update([
            'status'                => OrderStatus::Confirmed,
            'whatsapp_confirmed_at' => now(),
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status'               => OrderStatus::Cancelled,
            'whatsapp_cancelled_at'=> now(),
        ]);
    }
}
