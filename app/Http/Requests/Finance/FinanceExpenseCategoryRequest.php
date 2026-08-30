<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\FinanceExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', FinanceExpenseCategory::class);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $categoryId = $this->route('category')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('finance_expense_categories', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($categoryId),
            ],
            'group' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
