<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancePayoutFrequency;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's own finance setup for one external delivery provider —
 * default fees + COD payout schedule + reconciliation bank account.
 * `delivery_providers` is a global, org-agnostic catalogue, so this is where
 * "our" numbers for that provider actually live. One row per
 * (organization, delivery_provider) — see the creating migration's unique
 * index.
 */
class DeliveryProviderFinanceSetting extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'delivery_provider_id',
        'default_delivery_fee',
        'default_return_fee',
        'default_refusal_fee',
        'cod_fee_fixed',
        'cod_fee_percent',
        'payout_frequency',
        'period_anchor_date',
        'payout_delay_days',
        'default_bank_account_id',
        'is_cod_enabled',
        'is_active',
    ];

    protected $casts = [
        'default_delivery_fee' => 'decimal:2',
        'default_return_fee' => 'decimal:2',
        'default_refusal_fee' => 'decimal:2',
        'cod_fee_fixed' => 'decimal:2',
        'cod_fee_percent' => 'decimal:2',
        'payout_frequency' => FinancePayoutFrequency::class,
        'period_anchor_date' => 'date:Y-m-d',
        'payout_delay_days' => 'integer',
        'is_cod_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'default_bank_account_id');
    }
}
