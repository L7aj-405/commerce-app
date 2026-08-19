<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class City extends Model
{
    use HasUlids;

    protected $fillable = ['country_code', 'code', 'name', 'region', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_service_areas')
            ->withPivot(['priority', 'is_active', 'settings'])
            ->withTimestamps();
    }
}
