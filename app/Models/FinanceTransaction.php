<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only cash/sales ledger. Never updated or deleted by application
 * code — a correction is always a new row (see FinanceTransactionService).
 */
class FinanceTransaction extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'account_id',
        'direction',
        'type',
        'sequence',
        'amount',
        'currency',
        'occurred_at',
        'source_type',
        'source_id',
        'reference',
        'description',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'direction' => FinanceTransactionDirection::class,
        'type' => FinanceTransactionType::class,
        'sequence' => 'integer',
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
        'metadata' => 'array',
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
}
