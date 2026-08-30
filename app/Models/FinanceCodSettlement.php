<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceCodSettlementStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A batch settlement from an external delivery company for a set of COD orders it collected on our behalf. */
class FinanceCodSettlement extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'carrier_name',
        'settlement_date',
        'period_start',
        'period_end',
        'gross_cod_amount',
        'delivery_fees',
        'adjustments',
        'net_received',
        'account_id',
        'reference',
        'notes',
        'status',
        'settled_at',
        'created_by',
    ];

    protected $casts = [
        'settlement_date' => 'date:Y-m-d',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'gross_cod_amount' => 'decimal:2',
        'delivery_fees' => 'decimal:2',
        'adjustments' => 'decimal:2',
        'net_received' => 'decimal:2',
        'status' => FinanceCodSettlementStatus::class,
        'settled_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FinanceCodSettlementItem::class);
    }

    public function isDraft(): bool
    {
        return $this->status === FinanceCodSettlementStatus::Draft;
    }
}
