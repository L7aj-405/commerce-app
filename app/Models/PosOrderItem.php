<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosOrderItem extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'pos_order_id',
        'product_id',
        'variant_id',
        'product_name',
        'product_sku',
        'unit_price',
        'subtotal',
        'line_total',
        'quantity',
        'discount_percent',
        'discount_amount',
        'tax_percent',
        'tax_amount',
    ];

    protected $casts = [
        'unit_price'       => 'decimal:2',
        'subtotal'         => 'decimal:2',
        'line_total'       => 'decimal:2',
        'quantity'         => 'integer',
        'discount_percent' => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'tax_percent'      => 'decimal:2',
        'tax_amount'       => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
