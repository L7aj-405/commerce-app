<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'store_id',
        'external_id',
        'platform',
        'name',
        'description',
        'sku',
        'type',
        'status',
        'price',
        'cost',
        'compare_price',
        'images',
        'featured_image',
        'seo_title',
        'seo_description',
        'metadata',
        'synced_at',
    ];

    protected $casts = [
        'images' => 'json',
        'metadata' => 'json',
        'synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('name');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isSimple(): bool
    {
        return $this->type === 'simple';
    }

    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    public function getTotalStock(string $warehouseId = null): int
    {
        $query = $this->stocks();

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (int) $query->sum('quantity');
    }

    public function getStockByWarehouse(string $warehouseId = null): int
{
    if (!$warehouseId) {
        $warehouseId = $this->store->getPrimaryWarehouse()?->id;
    }

    return (int) $this->stocks()
        ->where('warehouse_id', $warehouseId)
        ->sum('quantity');
}

    public function getTotalVariantStock(?string $warehouseId = null): int
    {
        $query = $this->stocks()->whereNotNull('variant_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (int) $query->sum('quantity');
    }

    public function getDisplayStock(?string $warehouseId = null): int
    {
        return $this->isVariable()
            ? $this->getTotalVariantStock($warehouseId)
            : $this->getTotalStock($warehouseId);
    }

    /** Returns all unique attribute names across all variants: ['Color', 'Size'] */
    public function getAttributeNames(): array
    {
        return $this->variants
            ->flatMap(fn ($v) => array_keys($v->getAttribute('attributes') ?? []))
            ->unique()
            ->values()
            ->all();
    }

    /** Returns all known values for a single attribute: getAttributeValues('Color') → ['Blue', 'Red'] */
    public function getAttributeValues(string $name): array
    {
        return $this->variants
            ->map(fn ($v) => ($v->getAttribute('attributes') ?? [])[$name] ?? null)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function getVariantCount(): int
    {
        return $this->variants()->count();
    }

}