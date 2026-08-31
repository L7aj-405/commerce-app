<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCodSettlementItem extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['finance_cod_settlement_id', 'order_id', 'amount', 'expected_fee', 'fee_source'];

    protected $casts = ['amount' => 'decimal:2', 'expected_fee' => 'decimal:2'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinanceCodSettlement::class, 'finance_cod_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
