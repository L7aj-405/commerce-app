<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinancePaymentMethod;
use App\Models\FinanceExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense
            ? $this->user()->can('update', $expense)
            : $this->user()->can('create', FinanceExpense::class);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'category_id' => [
                'required', 'string',
                Rule::exists('finance_expense_categories', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'vendor_id' => [
                'nullable', 'string',
                Rule::exists('finance_vendors', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'store_id' => [
                'nullable', 'string',
                Rule::in($storeIds->all()),
            ],
            'expense_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:expense_date'],
            'payment_method' => ['nullable', Rule::enum(FinancePaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
