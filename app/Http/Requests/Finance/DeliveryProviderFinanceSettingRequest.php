<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinancePayoutFrequency;
use App\Models\DeliveryProviderFinanceSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeliveryProviderFinanceSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', DeliveryProviderFinanceSetting::class);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;

        return [
            'default_delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'default_return_fee' => ['nullable', 'numeric', 'min:0'],
            'default_refusal_fee' => ['nullable', 'numeric', 'min:0'],
            'cod_fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'cod_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payout_frequency' => ['required', Rule::enum(FinancePayoutFrequency::class)],
            'period_anchor_date' => ['nullable', 'date'],
            'payout_delay_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'default_bank_account_id' => [
                'nullable', 'string',
                Rule::exists('finance_accounts', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'is_cod_enabled' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
