<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Warehouse extends Model
{
    use BelongsToTenantThroughWarehouse, SoftDeletes, HasUlids;

    /** Sellable inventory. Everything that sums "stock on hand" counts these. */
    public const TYPE_STANDARD = 'standard';

    /** Written-off goods returned in unsellable condition. Never offered for sale. */
    public const TYPE_DAMAGED = 'damaged';

    /** Held pending a decision (e.g. supplier claim). Not sellable. */
    public const TYPE_QUARANTINE = 'quarantine';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'owner_organization_id',
        'operator_organization_id',
        'name',
        'type',
        'location',
        'address',
        'phone',
        'email',
        'city',
        'state',
        'country',
        'zip',
        'is_active',
        'is_default',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ============================================
    // SCOPES
    // ============================================

    /** Warehouses whose stock may be sold and pushed to storefronts. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_STANDARD);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isSellable(): bool
    {
        return $this->type === self::TYPE_STANDARD;
    }

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Get the user that owns the warehouse.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    public function ownerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owner_organization_id');
    }

    public function operatorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'operator_organization_id');
    }

    public function accessibleOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'warehouse_organization_access')
            ->withPivot(['is_active', 'settings'])
            ->withTimestamps();
    }

    /**
     * Get the stores this warehouse serves.
     */
    public function stores(): BelongsToMany
{
    return $this->belongsToMany(Store::class, 'warehouse_store')
        ->withPivot('is_primary', 'priority')
        ->withTimestamps();
}

    public function serviceCities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'warehouse_service_areas')
            ->withPivot(['priority', 'is_active', 'settings'])
            ->withTimestamps();
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(WarehouseInventoryBalance::class);
    }

    /**
     * Get the stocks in this warehouse.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Get the stock movements in this warehouse.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Get the full address string.
     */
    public function getFullAddress(): string
    {
        return collect([
            $this->address,
            $this->city,
            $this->state,
            $this->zip,
            $this->country,
        ])->filter()->join(', ');
    }

    /**
     * Get the total number of unique products in stock.
     */
    public function getTotalProducts(): int
    {
        return $this->stocks()
            ->where('quantity', '>', 0)
            ->distinct('product_id')
            ->count();
    }

}