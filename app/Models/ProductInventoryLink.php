<?php

declare(strict_types=1);
namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInventoryLink extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id', 'inventory_item_id', 'product_id', 'units_per_sale'];
    protected $casts = ['units_per_sale' => 'decimal:4'];
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
