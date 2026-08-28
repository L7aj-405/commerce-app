<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store's credentials + settings for one delivery provider (e.g. Ozon
 * Express). `credentials` is encrypted at rest and must never be included in
 * any array/JSON representation returned to the frontend — see toApiArray().
 */
class DeliveryConnection extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'store_id', 'organization_id', 'provider_code', 'name',
        'credentials', 'settings', 'status', 'last_tested_at', 'last_error', 'created_by',
        'last_city_sync_at', 'last_city_sync_error', 'last_city_sync_count',
        // Sync diagnostics — see the 2026_08_27 migration doc comment on
        // why these are real columns, not `settings` keys.
        'last_city_sync_pickup_district_id', 'last_city_sync_page_count',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_tested_at' => 'datetime',
            'last_city_sync_at' => 'datetime',
            'last_city_sync_count' => 'integer',
            'last_city_sync_page_count' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function isOzon(): bool
    {
        return $this->provider_code === DeliveryProvider::OZON;
    }

    public function isSendit(): bool
    {
        return $this->provider_code === DeliveryProvider::SENDIT;
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** Ozon base URL with the api_key masked — safe to log or put in an exception message. */
    public function maskedBaseUrl(): string
    {
        $customerId = $this->credential('customer_id') ?? '?';

        return "customers/{$customerId}/****";
    }

    /**
     * Safe shape for Inertia/JSON responses — credentials never leave the
     * server, only whether they are set.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        // Sendit's credential pair is public_key/secret_key, not Ozon's
        // customer_id/api_key — has_credentials/public_key are computed per
        // provider so an Ozon connection's shape never changes (existing
        // callers read `customer_id` directly). public_key is safe to
        // expose (it identifies the account, like Ozon's customer_id);
        // secret_key/api_key never appear here, same as before.
        $hasCredentials = $this->isSendit()
            ? filled($this->credentials['public_key'] ?? null) && filled($this->credentials['secret_key'] ?? null)
            : filled($this->credentials['customer_id'] ?? null) && filled($this->credentials['api_key'] ?? null);

        return [
            'id' => $this->id,
            'provider_code' => $this->provider_code,
            'name' => $this->name,
            'status' => $this->status,
            'has_credentials' => $hasCredentials,
            'customer_id' => $this->credential('customer_id'),
            'public_key' => $this->isSendit() ? $this->credential('public_key') : null,
            'settings' => $this->settings,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_error' => $this->last_error,
            // City sync is deliberately reported as its own state, never
            // folded into `status`/`last_error` above — see toApiArray()'s
            // callers for the two-badge UI this backs.
            'last_city_sync_at' => $this->last_city_sync_at?->toIso8601String(),
            'last_city_sync_error' => $this->last_city_sync_error,
            'last_city_sync_count' => $this->last_city_sync_count,
            'last_city_sync_pickup_district_id' => $this->last_city_sync_pickup_district_id,
            'last_city_sync_page_count' => $this->last_city_sync_page_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
