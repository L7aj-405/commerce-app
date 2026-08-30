<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceCourierDepositStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cash handover from an internal delivery agent back to the accountant, for a set of COD orders they delivered. */
class FinanceCourierDeposit extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'courier_id',
        'deposit_date',
        'expected_amount',
        'cash_received',
        'difference',
        'account_id',
        'reference',
        'notes',
        'status',
        'confirmed_at',
        'created_by',
    ];

    protected $casts = [
        'deposit_date' => 'date:Y-m-d',
        'expected_amount' => 'decimal:2',
        'cash_received' => 'decimal:2',
        'difference' => 'decimal:2',
        'status' => FinanceCourierDepositStatus::class,
        'confirmed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
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
        return $this->hasMany(FinanceCourierDepositItem::class);
    }

    public function isDraft(): bool
    {
        return $this->status === FinanceCourierDepositStatus::Draft;
    }
}
