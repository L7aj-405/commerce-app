<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Stock Transfer / Bon de Sortie (exit slip): the authoritative record of goods
 * leaving a source warehouse — either to a sibling warehouse (a real stock move)
 * or out to a team / external post. The per-warehouse quantity audit lives in
 * stock_ledger (type = transfer); this table + its items back the printed slip.
 */
class StockTransfer extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    /** Destination is another warehouse this store owns — stock moves between rows. */
    public const KIND_WAREHOUSE = 'warehouse';

    /** Destination is an internal team / member post — goods leave the tracked warehouses. */
    public const KIND_TEAM = 'team';

    /** Destination is anything else (customer pickup, external party) — goods leave. */
    public const KIND_EXTERNAL = 'external';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'reference',
        'source_warehouse_id',
        'destination_kind',
        'destination_warehouse_id',
        'destination_member_id',
        'destination_label',
        'responsible_member_id',
        'created_by',
        'status',
        'transfer_date',
        'notes',
        'total_quantity',
    ];

    protected $casts = [
        'transfer_date'  => 'date',
        'total_quantity' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function destinationMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destination_member_id');
    }

    public function responsibleMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_member_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Human label for the destination regardless of kind. */
    public function destinationName(): string
    {
        return match ($this->destination_kind) {
            self::KIND_WAREHOUSE => $this->destinationWarehouse?->name ?? 'Warehouse',
            default              => $this->destination_label ?: ($this->destinationMember?->name ?? 'External'),
        };
    }
}
