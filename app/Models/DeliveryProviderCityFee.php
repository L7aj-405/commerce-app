<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A manual, organization-entered fee override for one (provider, city) pair —
 * overrides the provider's org-level default fee. See the creating
 * migration's docblock for where this sits relative to the provider's own
 * synced API pricing (DeliveryProviderCity).
 */
class DeliveryProviderCityFee extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'delivery_provider_id',
        'city_id',
        'provider_city_id',
        'city_name',
        'provider_city_code',
        'delivery_fee',
        'return_fee',
        'refusal_fee',
        'cod_fee_fixed',
        'cod_fee_percent',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'delivery_fee' => 'decimal:2',
        'return_fee' => 'decimal:2',
        'refusal_fee' => 'decimal:2',
        'cod_fee_fixed' => 'decimal:2',
        'cod_fee_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'date:Y-m-d',
        'ends_at' => 'date:Y-m-d',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    /** The internal canonical city (App\Models\City), when matched by id rather than the provider's own city list. */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** The provider's own synced city (App\Models\DeliveryProviderCity), when matched by id — preferred whenever it exists. */
    public function providerCity(): BelongsTo
    {
        return $this->belongsTo(DeliveryProviderCity::class, 'provider_city_id');
    }

    /** Is this fee matched by a real city reference, or only by legacy free text? */
    public function hasCityReference(): bool
    {
        return $this->provider_city_id !== null || $this->city_id !== null;
    }

    /** Active AND (no date window, or the given date falls inside it) — an expired/future/inactive row never applies. */
    public function scopeApplicableOn(Builder $query, Carbon|string $date): Builder
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date));
    }

    public function isApplicableOn(Carbon|string $date): bool
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->toDateString() > $date) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->toDateString() < $date) {
            return false;
        }

        return true;
    }
}
