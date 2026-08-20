<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformConnection extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    const PLATFORM_WOOCOMMERCE = 'woocommerce';
    const PLATFORM_SHOPIFY     = 'shopify';
    const PLATFORM_YOUCAN      = 'youcan';

    const CONNECTION_METHOD_APP_OAUTH  = 'app_oauth';
    const CONNECTION_METHOD_ADMIN_TOKEN = 'admin_token';
    const CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS = 'admin_client_credentials';
    const CONNECTION_METHOD_WEBHOOK     = 'webhook';

    const WEBHOOK_STATUS_PENDING  = 'pending';
    const WEBHOOK_STATUS_VERIFIED = 'verified';
    const WEBHOOK_STATUS_FAILED   = 'failed';

    protected $fillable = [
        'store_id',
        'platform',
        'connection_method',
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
        'webhook_status',
        'last_webhook_at',
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
            'last_webhook_at' => 'datetime',
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

    public function productListings(): HasMany
    {
        return $this->hasMany(ProductChannelListing::class);
    }

    public function variantListings(): HasMany
    {
        return $this->hasMany(ProductVariantChannelListing::class);
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

    public function isWebhookMethod(): bool
    {
        return $this->connection_method === self::CONNECTION_METHOD_WEBHOOK;
    }

    public function isWebhookVerified(): bool
    {
        return $this->webhook_status === self::WEBHOOK_STATUS_VERIFIED;
    }

    public function getLastSyncLog(): ?SyncLog
    {
        return $this->syncLogs()->latest('started_at')->first();
    }
}
