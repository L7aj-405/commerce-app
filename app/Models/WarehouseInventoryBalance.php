<?php

declare(strict_types=1);
namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseInventoryBalance extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable = ['organization_id','inventory_item_id','warehouse_id','on_hand','reserved','transfer_reserved','reorder_level'];
    protected $casts = ['on_hand'=>'integer','reserved'=>'integer','transfer_reserved'=>'integer','reorder_level'=>'integer'];
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function available(): int { return max(0, $this->on_hand - $this->reserved - $this->transfer_reserved); }
}
