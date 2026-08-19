<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Inventory\InventoryCompatibilityBridge;

use App\Models\Concerns\BelongsToTenantThroughInventory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use BelongsToTenantThroughInventory, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'product_id',
        'variant_id',
        'warehouse_id',
        'quantity',
        'reserved',
        'reorder_level',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
        'reorder_level' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (Stock $stock): void {
            app(InventoryCompatibilityBridge::class)->fromLegacyStock($stock);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getAvailable(): int
    {
        return max(0, $this->quantity - $this->reserved);
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->reorder_level;
    }
}