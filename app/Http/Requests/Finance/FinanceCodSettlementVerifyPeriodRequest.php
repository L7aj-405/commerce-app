<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceCodSettlementVerifyPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasStorePermission($this->user()->getActiveStore(), 'finance.manage_cod_settlements');
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;

        return [
            'delivery_provider_id' => [
                'required', 'string',
                Rule::exists('delivery_provider_finance_settings', 'delivery_provider_id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'string'],
            'actual_received_amount' => ['required', 'numeric'],
            'account_id' => [
                'required', 'string',
                Rule::exists('finance_accounts', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId)),
            ],
            'received_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
