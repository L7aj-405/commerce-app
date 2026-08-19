<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryAdjustment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'product_id',
        'source',
        'adjustable_type',
        'adjustable_id',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'sync_status',
        'sync_metadata',
        'synced_at',
        'notes',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after'  => 'integer',
        'sync_metadata'   => 'array',
        'synced_at'       => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function adjustable(): MorphTo
    {
        return $this->morphTo();
    }
}
