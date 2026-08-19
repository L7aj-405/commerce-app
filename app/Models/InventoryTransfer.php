<?php

declare(strict_types=1);
namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends Model
{
    use BelongsToOrganization, HasUlids;
    public const REQUESTED='requested'; public const APPROVED='approved'; public const IN_TRANSIT='in_transit'; public const RECEIVED='received'; public const CANCELLED='cancelled';
    protected $fillable=['organization_id','source_warehouse_id','destination_warehouse_id','reference','status','reason','requested_by','approved_at','shipped_at','received_at','notes'];
    protected $casts=['approved_at'=>'datetime','shipped_at'=>'datetime','received_at'=>'datetime'];
    public function sourceWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class,'source_warehouse_id'); }
    public function destinationWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class,'destination_warehouse_id'); }
    public function items(): HasMany { return $this->hasMany(InventoryTransferItem::class); }
}
