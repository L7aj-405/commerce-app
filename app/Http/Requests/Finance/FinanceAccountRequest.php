<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinanceAccountType;
use App\Models\FinanceAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account
            ? $this->user()->can('update', $account)
            : $this->user()->can('create', FinanceAccount::class);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $accountId = $this->route('account')?->id;
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('finance_accounts', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($accountId),
            ],
            'type' => ['required', Rule::enum(FinanceAccountType::class)],
            'store_id' => ['nullable', 'string', Rule::in($storeIds->all())],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
