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

class Product extends Model
{
    use BelongsToTenant, SoftDeletes, HasUlids;

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
        'barcode',
        'category',
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

    /** Platform-specific identities for this canonical catalog product. */
    public function channelListings(): HasMany
    {
        return $this->hasMany(ProductChannelListing::class);
    }

    public function listingForConnection(PlatformConnection|string $connection): ?ProductChannelListing
    {
        $connectionId = $connection instanceof PlatformConnection ? $connection->id : $connection;

        return $this->channelListings()
            ->where('platform_connection_id', $connectionId)
            ->first();
    }

    /**
     * Resolve the remote product id for one connection. Listing tables are the
     * source of truth; legacy columns are only a migration fallback.
     */
    public function externalIdForConnection(PlatformConnection $connection): ?string
    {
        $externalId = $this->listingForConnection($connection)?->external_product_id;

        if (is_string($externalId) && $externalId !== '') {
            return $externalId;
        }

        return $this->platform === $connection->platform && ! empty($this->external_id)
            ? (string) $this->external_id
            : null;
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('position');
    }

    public function inventoryLink(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProductInventoryLink::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** Stock held in sellable warehouses only — excludes damaged / quarantine. */
    public function sellableStocks(): HasMany
    {
        return $this->stocks()->whereHas('warehouse', fn ($w) => $w->sellable());
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Adds `total_stock` (sellable units) and `damaged_stock` as aggregate
     * attributes.
     *
     * Use this instead of a bare withSum('stocks as total_stock', 'quantity'):
     * since damaged returns are restocked into a warehouse of their own, an
     * unscoped sum silently reports written-off units as available to sell.
     */
    public function scopeWithSellableStock(Builder $query): Builder
    {
        return $query
            ->withSum([
                'stocks as total_stock' => fn ($q) => $q->whereHas(
                    'warehouse', fn ($w) => $w->where('type', Warehouse::TYPE_STANDARD)
                ),
            ], 'quantity')
            ->withSum([
                'stocks as damaged_stock' => fn ($q) => $q->whereHas(
                    'warehouse', fn ($w) => $w->where('type', Warehouse::TYPE_DAMAGED)
                ),
            ], 'quantity');
    }

    public function isSimple(): bool
    {
        return $this->type === 'simple';
    }

    public function isVariable(): bool
    {
        return $this->type === 'variable';
    }

    public function getTotalStock(?string $warehouseId = null): int
    {
        // No warehouse asked for means "how many can we sell", which must skip
        // the damaged location; an explicit id is answered as asked.
        $query = $warehouseId
            ? $this->stocks()->where('warehouse_id', $warehouseId)
            : $this->sellableStocks();

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
        $query = $warehouseId
            ? $this->stocks()->where('warehouse_id', $warehouseId)
            : $this->sellableStocks();

        return (int) $query->whereNotNull('variant_id')->sum('quantity');
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

    public function getThumbnailUrlAttribute(): ?string
    {
        $images = $this->getAttribute('images');

        if (is_array($images) && isset($images[1]) && is_string($images[1]) && $images[1] !== '') {
            return $images[1];
        }

        return $this->featured_image;
    }

}