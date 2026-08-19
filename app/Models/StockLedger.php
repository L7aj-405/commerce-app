<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLedger extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'stock_ledger';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'product_id',
        'variant_id',
        'type',
        'source_type',
        'source_id',
        'quantity_change',
        'stock_before',
        'stock_after',
        'reference_number',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'stock_before'    => 'integer',
        'stock_after'     => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
