<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ProductVariant extends Model
{
    use SoftDeletes, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'product_id',
        'external_id',
        'name',
        'sku',
        'price',
        'cost',
        'compare_price',
        'attributes',
        'images',
        'featured_image',
    ];

    protected $casts = [
        'attributes' => 'json',
        'images'     => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'variant_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_attribute_values',
            'product_variant_id',
            'product_attribute_value_id'
        )->with('attribute');
    }

    /**
     * Sync pivot records and rebuild the JSON attributes column so platform
     * connectors (which read the JSON) stay in sync automatically.
     *
     * @param  array<string>  $valueIds  ProductAttributeValue IDs to attach
     */
    public function syncAttributeValues(array $valueIds): void
    {
        $this->attributeValues()->sync($valueIds);

        $attrs = ProductAttributeValue::whereIn('id', $valueIds)
            ->with('attribute')
            ->get()
            ->groupBy(fn ($v) => $v->attribute->name)
            ->map(function ($vals) {
                $values = $vals->pluck('value')->all();
                return count($values) === 1 ? $values[0] : $values;
            })
            ->toArray();

        $this->update(['attributes' => $attrs ?: null]);
    }

    public function getTotalStock(?string $warehouseId = null): int
    {
        $query = $this->stocks();

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return (int) $query->sum('quantity');
    }

    public function getStockByWarehouse(?string $warehouseId = null): int
    {
        if ($warehouseId === null) {
            $warehouseId = $this->product?->store?->getPrimaryWarehouse()?->id;
        }

        return (int) $this->stocks()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('quantity');
    }

    /** Return just the attribute values: "XL / Red" */
    public function getAttributeSummary(): string
    {
        // Prefer structured pivot values when already eager-loaded
        if ($this->relationLoaded('attributeValues') && $this->attributeValues->isNotEmpty()) {
            return $this->attributeValues
                ->groupBy(fn ($v) => $v->attribute?->name ?? '')
                ->map(fn ($vals) => $vals->pluck('value')->join(', '))
                ->values()
                ->join(' / ');
        }

        // Fallback to JSON column (legacy / unsynced data)
        $attrs = $this->getAttribute('attributes');

        if (empty($attrs) || !is_array($attrs)) {
            return '';
        }

        return implode(' / ', array_map(
            fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v,
            array_values($attrs)
        ));
    }

    /** Return variant->name if set, otherwise fall back to attribute summary. */
    public function getDisplayName(): string
    {
        $name = $this->name ?? '';

        if (trim($name) !== '') {
            return $name;
        }

        $summary = $this->getAttributeSummary();

        return $summary !== '' ? $summary : ($this->sku ?: 'Variant');
    }

    /** "Color: Red, Size: M" */
    public function getAttributesString(): string
    {
        $attrs = $this->getAttribute('attributes');

        if (empty($attrs) || !is_array($attrs)) {
            return '';
        }

        return collect($attrs)
            ->map(fn ($value, $key) => $key . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value))
            ->join(', ');
    }

    /** Alias kept for backwards compatibility. */
    public function getAttributeString(): string
    {
        return $this->getAttributesString();
    }

    /** "350.00 MAD" */
    public function getFormattedPrice(): string
    {
        $currency = $this->product?->store?->currency ?? 'MAD';

        return number_format((float) $this->price, 2) . ' ' . strtoupper($currency);
    }

    /** "0.00 MAD" */
    public function getFormattedCost(): string
    {
        $currency = $this->product?->store?->currency ?? 'MAD';

        return number_format((float) $this->cost, 2) . ' ' . strtoupper($currency);
    }

    /** Alias for getFormattedPrice(). */
    public function getPriceLabel(): string
    {
        return $this->getFormattedPrice();
    }

    public function canPushToPlatform(): bool
    {
        return !empty($this->external_id) && !empty($this->product?->external_id);
    }

    /**
     * Delete the variant together with its stock records and stock movements in one transaction.
     * Call this instead of delete() to avoid orphaned stock rows.
     */
    public function deleteWithStock(): void
    {
        DB::transaction(function (): void {
            StockMovement::where('variant_id', $this->id)->delete();
            Stock::where('variant_id', $this->id)->delete();
            $this->delete();
        });
    }

    /** "Color: Blue, Size: M, Type: Pro" */
    public function getAttributeFormatted(): string
    {
        $attrs = $this->getAttribute('attributes');

        if (empty($attrs) || !is_array($attrs)) {
            return '';
        }

        return collect($attrs)
            ->map(fn ($value, $key) => $key . ': ' . (is_array($value) ? implode(', ', $value) : (string) $value))
            ->join(', ');
    }
}
