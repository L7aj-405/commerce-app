<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ProductVariant extends Model
{
    use BelongsToTenantThroughProduct, SoftDeletes, HasUlids;

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

    /** Platform-specific identities for this canonical variant. */
    public function channelListings(): HasMany
    {
        return $this->hasMany(ProductVariantChannelListing::class, 'product_variant_id');
    }

    public function listingForConnection(PlatformConnection|string $connection): ?ProductVariantChannelListing
    {
        $connectionId = $connection instanceof PlatformConnection ? $connection->id : $connection;

        return $this->channelListings()
            ->where('platform_connection_id', $connectionId)
            ->first();
    }

    public function externalIdForConnection(PlatformConnection $connection): ?string
    {
        $externalId = $this->listingForConnection($connection)?->external_variant_id;

        if (is_string($externalId) && $externalId !== '') {
            return $externalId;
        }

        // Legacy fallback is safe only when the parent product itself belongs to
        // this platform. A raw variant.external_id has no platform dimension.
        return $this->product?->platform === $connection->platform && ! empty($this->external_id)
            ? (string) $this->external_id
            : null;
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
        $requestedIds = collect($valueIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $values = ProductAttributeValue::query()
            ->whereIn('id', $requestedIds)
            ->whereHas('attribute', fn ($query) => $query->where('product_id', $this->product_id))
            ->with('attribute')
            ->get();

        if ($values->count() !== $requestedIds->count()) {
            throw new \InvalidArgumentException('One or more attribute values do not belong to this product.');
        }

        // Validate ownership before touching the pivot. This prevents a crafted
        // request from attaching attribute values belonging to another tenant.
        $this->attributeValues()->sync($values->pluck('id')->all());

        $attrs = $values
            ->groupBy(fn ($value) => $value->attribute->name)
            ->map(function ($group) {
                $groupValues = $group->pluck('value')->all();
                return count($groupValues) === 1 ? $groupValues[0] : $groupValues;
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

    public function canPushToPlatform(?PlatformConnection $connection = null): bool
    {
        if ($connection !== null) {
            return $this->externalIdForConnection($connection) !== null
                && $this->product?->externalIdForConnection($connection) !== null;
        }

        return $this->channelListings()->exists() || (! empty($this->external_id) && ! empty($this->product?->external_id));
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

    public function inventoryLink(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(VariantInventoryLink::class, 'product_variant_id');
    }
}
