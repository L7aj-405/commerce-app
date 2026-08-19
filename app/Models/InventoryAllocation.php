<?php

declare(strict_types=1);
namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryAllocation extends Model
{
    use BelongsToOrganization, HasUlids;
    public const STATUS_RESERVED='reserved';
    public const STATUS_WAITING_TRANSFER='waiting_transfer';
    public const STATUS_INSUFFICIENT='insufficient_stock';
    public const STATUS_CONSUMED='consumed';
    public const STATUS_RELEASED='released';

    protected $fillable=['organization_id','store_id','source_type','source_id','city_id','warehouse_id','status','strategy','fill_ratio','allocated_at','released_at','consumed_at','notes'];
    protected $casts=['fill_ratio'=>'decimal:4','allocated_at'=>'datetime','released_at'=>'datetime','consumed_at'=>'datetime'];
    public function source(): MorphTo { return $this->morphTo(); }
    public function city(): BelongsTo { return $this->belongsTo(City::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function reservations(): HasMany { return $this->hasMany(InventoryReservation::class, 'allocation_id'); }
    public function transfers(): HasMany { return $this->hasMany(InventoryTransferItem::class, 'allocation_id'); }
}
