<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Warehouse extends Model
{
    use SoftDeletes, HasUlids;

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
        'name',
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
    // RELATIONSHIPS
    // ============================================

    /**
     * Get the user that owns the warehouse.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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