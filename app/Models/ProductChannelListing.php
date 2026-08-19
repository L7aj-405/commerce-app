<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProductChannelListing extends Model
{
    use BelongsToTenantThroughProduct, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'product_id',
        'platform_connection_id',
        'external_product_id',
        'external_handle',
        'sync_mode',
        'sync_status',
        'last_pulled_at',
        'last_pushed_at',
        'remote_updated_at',
        'sync_policy',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sync_policy' => 'array',
            'metadata' => 'array',
            'last_pulled_at' => 'datetime',
            'last_pushed_at' => 'datetime',
            'remote_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $validate = static function (self $listing): void {
            $product = Product::withoutTenancy(fn () => Product::query()->find($listing->product_id));
            $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($listing->platform_connection_id));

            if ($product === null || $connection === null || $product->store_id !== $connection->store_id) {
                throw new LogicException('Product channel listing must connect a product and platform connection from the same store.');
            }
        };

        static::creating($validate);
        static::updating($validate);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }

    public function variantListings(): HasMany
    {
        return $this->hasMany(ProductVariantChannelListing::class, 'product_channel_listing_id');
    }
}
