<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductVariantChannelListing extends Model
{
    use BelongsToTenantThroughProduct, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'product_channel_listing_id',
        'platform_connection_id',
        'external_variant_id',
        'external_inventory_item_id',
        'sync_status',
        'last_pulled_at',
        'last_pushed_at',
        'remote_updated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
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
            $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($listing->product_variant_id));
            $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($listing->platform_connection_id));
            $productListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->product_channel_listing_id));

            if (
                $product === null
                || $variant === null
                || $connection === null
                || $productListing === null
                || $variant->product_id !== $product->id
                || $product->store_id !== $connection->store_id
                || $productListing->product_id !== $product->id
                || $productListing->platform_connection_id !== $connection->id
            ) {
                throw new LogicException('Variant channel listing ownership is inconsistent.');
            }
        };

        static::creating($validate);
        static::updating($validate);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function productListing(): BelongsTo
    {
        return $this->belongsTo(ProductChannelListing::class, 'product_channel_listing_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }
}
