<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceCodSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasStorePermission($this->user()->getActiveStore(), 'finance.manage_cod_settlements');
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();

        return [
            'store_id' => ['nullable', 'string', Rule::in($storeIds->all())],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'settlement_date' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'delivery_fees' => ['nullable', 'numeric', 'min:0'],
            'adjustments' => ['nullable', 'numeric'],
            'account_id' => [
                'nullable', 'string',
                Rule::exists('finance_accounts', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['required', 'string'],
        ];
    }
}
