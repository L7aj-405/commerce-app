<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductAttribute extends Model
{
    use BelongsToTenantThroughProduct, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = ['product_id', 'name', 'slug', 'position'];

    protected $casts = ['position' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'attribute_id')->orderBy('position');
    }

    public static function findOrCreateForProduct(string $productId, string $name): self
    {
        $slug = Str::slug($name);

        return self::firstOrCreate(
            ['product_id' => $productId, 'slug' => $slug],
            ['name' => $name]
        );
    }
}
