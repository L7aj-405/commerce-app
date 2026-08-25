<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One provider's city, as synced from its API (e.g. Ozon's /cities). */
class DeliveryProviderCity extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'store_id', 'provider_code', 'provider_city_id', 'city_name', 'raw_payload',
        'city_ref', 'delivered_price', 'returned_price', 'refused_price',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'delivered_price' => 'decimal:2',
            'returned_price' => 'decimal:2',
            'refused_price' => 'decimal:2',
        ];
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(CityDeliveryProviderMapping::class);
    }
}
