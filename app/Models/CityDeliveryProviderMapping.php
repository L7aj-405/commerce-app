<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Links an internal City to one provider's city (e.g. Ozon). */
class CityDeliveryProviderMapping extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['store_id', 'city_id', 'provider_code', 'delivery_provider_city_id'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function providerCity(): BelongsTo
    {
        return $this->belongsTo(DeliveryProviderCity::class, 'delivery_provider_city_id');
    }
}
