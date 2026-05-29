<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformConnection extends Model
{
    use HasUlids, SoftDeletes;

    const PLATFORM_WOOCOMMERCE = 'woocommerce';
    const PLATFORM_SHOPIFY     = 'shopify';
    const PLATFORM_YOUCAN      = 'youcan';

    protected $fillable = [
        'store_id',
        'platform',
        'label',
        'status',
        'is_syncing',
        'last_synced_at',
        'last_sync_error',
        'synced_products_count',
        'synced_orders_count',
        'api_url',
        'consumer_key',
        'consumer_secret',
        'access_token',
        'shop_domain',
        'webhook_secret',
        'settings',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'consumer_key'    => 'encrypted',
            'consumer_secret' => 'encrypted',
            'access_token'    => 'encrypted',
            'webhook_secret'  => 'encrypted',
            'is_syncing'      => 'boolean',
            'last_synced_at'  => 'datetime',
            'settings'        => 'array',
            'metadata'        => 'array',
        ];
    }

    // Relationships

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    // Helpers

    public function isWooCommerce(): bool
    {
        return $this->platform === self::PLATFORM_WOOCOMMERCE;
    }

    public function isShopify(): bool
    {
        return $this->platform === self::PLATFORM_SHOPIFY;
    }

    public function isYouCan(): bool
    {
        return $this->platform === self::PLATFORM_YOUCAN;
    }

    public function isConnected(): bool
    {
        return $this->status === 'active';
    }

    public function getLastSyncLog(): ?SyncLog
    {
        return $this->syncLogs()->latest('started_at')->first();
    }
}
