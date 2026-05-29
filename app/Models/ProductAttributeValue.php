<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ProductAttributeValue extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = ['attribute_id', 'value', 'slug'];

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

    public static function findOrCreateForAttribute(string $attributeId, string $value): self
    {
        $slug = Str::slug($value);

        return self::firstOrCreate(
            ['attribute_id' => $attributeId, 'slug' => $slug],
            ['value' => $value]
        );
    }
}
