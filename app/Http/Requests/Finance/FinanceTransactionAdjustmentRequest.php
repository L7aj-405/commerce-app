<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinanceTransactionDirection;
use App\Models\FinanceTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceTransactionAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinanceTransaction::class);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();

        return [
            'account_id' => [
                'nullable', 'string',
                Rule::exists('finance_accounts', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'store_id' => ['nullable', 'string', Rule::in($storeIds->all())],
            'direction' => ['required', Rule::enum(FinanceTransactionDirection::class)->except(FinanceTransactionDirection::Neutral)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
        ];
    }
}
