<?php

declare(strict_types=1);
namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservation extends Model
{
    use BelongsToOrganization, HasUlids;
    public const STATUS_ACTIVE='active';
    public const STATUS_WAITING_TRANSFER='waiting_transfer';
    public const STATUS_CONSUMED='consumed';
    public const STATUS_RELEASED='released';
    public const STATUS_INSUFFICIENT='insufficient';
    protected $fillable=['organization_id','allocation_id','inventory_item_id','warehouse_id','requested_quantity','reserved_quantity','shortage_quantity','status','inventory_transfer_id','metadata'];
    protected $casts=['requested_quantity'=>'integer','reserved_quantity'=>'integer','shortage_quantity'=>'integer','metadata'=>'array'];
    public function allocation(): BelongsTo { return $this->belongsTo(InventoryAllocation::class); }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function transfer(): BelongsTo { return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id'); }
}
