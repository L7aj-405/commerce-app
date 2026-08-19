<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single returned line and the inspector's verdict on it. The condition here
 * — not the order's status — decides where the units land: resellable goes back
 * to the primary warehouse, damaged goes to the damaged warehouse, missing
 * moves no stock at all.
 */
class OrderReturnItem extends Model
{
    use HasUlids;

    /** Good condition — restocked into active, sellable inventory. */
    public const CONDITION_RESELLABLE = 'resellable';

    /** Unsellable — moved to the damaged warehouse and written off. */
    public const CONDITION_DAMAGED = 'damaged';

    /** Never arrived. Recorded for the audit trail; no stock moves. */
    public const CONDITION_MISSING = 'missing';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'order_return_id',
        'product_id',
        'variant_id',
        'product_name',
        'product_sku',
        'quantity_ordered',
        'quantity_returned',
        'condition',
        'destination_warehouse_id',
        'stock_ledger_id',
        'refund_amount',
        'inspection_notes',
        'dispositioned_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered'  => 'integer',
            'quantity_returned' => 'integer',
            'refund_amount'     => 'decimal:2',
            'dispositioned_at'  => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public static function conditions(): array
    {
        return [self::CONDITION_RESELLABLE, self::CONDITION_DAMAGED, self::CONDITION_MISSING];
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StockLedger::class, 'stock_ledger_id');
    }

    /** The inspector has recorded a verdict on this line. */
    public function isDispositioned(): bool
    {
        return $this->dispositioned_at !== null;
    }

    /**
     * Stock has already been moved for this line. The disposition service skips
     * these, so a double-submitted inspection form cannot restock twice.
     */
    public function hasStockMovement(): bool
    {
        return $this->stock_ledger_id !== null;
    }

    /** Whether these units should go back into sellable inventory. */
    public function isResellable(): bool
    {
        return $this->condition === self::CONDITION_RESELLABLE;
    }
}
