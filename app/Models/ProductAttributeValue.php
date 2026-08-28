<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughProductAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ProductAttributeValue extends Model
{
    use BelongsToTenantThroughProductAttribute, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = ['attribute_id', 'value', 'slug', 'position', 'is_active'];

    protected $casts = ['position' => 'integer', 'is_active' => 'boolean'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_attribute_values',
            'product_attribute_value_id',
            'product_variant_id'
        );
    }

    /** Values the wizard should treat as real options — never a user-archived one. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function findOrCreateForAttribute(string $attributeId, string $value): self
    {
        $slug = Str::slug($value);

        return self::firstOrCreate(
            ['attribute_id' => $attributeId, 'slug' => $slug],
            ['value' => $value]
        );
    }
}
