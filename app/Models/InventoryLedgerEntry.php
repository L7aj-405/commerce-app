<?php

declare(strict_types=1);
namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class InventoryLedgerEntry extends Model
{
    use BelongsToOrganization, HasUlids;
    protected $fillable=['organization_id','inventory_item_id','warehouse_id','event_type','on_hand_delta','reserved_delta','transfer_reserved_delta','on_hand_after','reserved_after','transfer_reserved_after','source_type','source_id','reference','notes','actor_user_id'];
    protected $casts=['on_hand_delta'=>'integer','reserved_delta'=>'integer','transfer_reserved_delta'=>'integer','on_hand_after'=>'integer','reserved_after'=>'integer','transfer_reserved_after'=>'integer'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Inventory ledger entries are append-only.'));
    }
    public function inventoryItem(): BelongsTo { return $this->belongsTo(InventoryItem::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function source(): MorphTo { return $this->morphTo(); }
}
