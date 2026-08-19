<?php

declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransferItem extends Model
{
    use HasUlids;
    protected $fillable=['inventory_transfer_id','inventory_item_id','quantity','received_quantity','allocation_id'];
    protected $casts=['quantity'=>'integer','received_quantity'=>'integer'];
    public function transfer(): BelongsTo { return $this->belongsTo(InventoryTransfer::class,'inventory_transfer_id'); }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function allocation(): BelongsTo { return $this->belongsTo(InventoryAllocation::class); }
}
