<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'sku', 'barcode', 'name', 'unit', 'track_inventory',
        'reorder_level', 'is_active', 'metadata',
    ];

    protected $casts = [
        'track_inventory' => 'boolean', 'is_active' => 'boolean',
        'reorder_level' => 'integer', 'metadata' => 'array',
    ];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function balances(): HasMany { return $this->hasMany(WarehouseInventoryBalance::class); }
    public function productLinks(): HasMany { return $this->hasMany(ProductInventoryLink::class); }
    public function variantLinks(): HasMany { return $this->hasMany(VariantInventoryLink::class); }

    public function totalOnHand(): int { return (int) $this->balances()->sum('on_hand'); }
    public function totalReserved(): int { return (int) $this->balances()->sum('reserved'); }
    public function totalAvailable(): int
    {
        return (int) $this->balances()->get()->sum(fn (WarehouseInventoryBalance $b) => $b->available());
    }
}
