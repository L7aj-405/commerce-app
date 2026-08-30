<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceCodCollectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasStorePermission($this->user()->getActiveStore(), 'finance.mark_collected');
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;

        return [
            'account_id' => [
                'required', 'string',
                Rule::exists('finance_accounts', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'amount_collected' => ['required', 'numeric', 'min:0.01'],
            'collected_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
