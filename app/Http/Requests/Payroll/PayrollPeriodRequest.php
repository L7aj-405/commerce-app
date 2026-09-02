<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Models\PayrollPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PayrollPeriod::class);
    }

    public function rules(): array
    {
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();

        return [
            'store_id' => ['nullable', 'string', Rule::in($storeIds->all())],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'pay_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
