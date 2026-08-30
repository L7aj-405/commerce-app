<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinanceExpenseStatus;
use App\Enums\FinanceRecurringFrequency;
use App\Models\FinanceRecurringExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recurring = $this->route('recurring');

        return $recurring
            ? $this->user()->can('update', $recurring)
            : $this->user()->can('create', FinanceRecurringExpense::class);
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
            'frequency' => ['required', Rule::enum(FinanceRecurringFrequency::class)],
            'starts_at' => ['required', 'date'],
            'next_due_at' => ['required', 'date'],
            'reminder_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'auto_create_expense' => ['sometimes', 'boolean'],
            'generated_expense_status' => ['nullable', Rule::enum(FinanceExpenseStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
