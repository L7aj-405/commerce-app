<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceCourierDepositItem extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['finance_courier_deposit_id', 'order_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(FinanceCourierDeposit::class, 'finance_courier_deposit_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
