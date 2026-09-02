<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Enums\SalaryPaymentFrequency;
use App\Enums\SalaryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeSalaryProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    public function rules(): array
    {
        return [
            'salary_type' => ['nullable', Rule::enum(SalaryType::class)],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_frequency' => ['nullable', Rule::enum(SalaryPaymentFrequency::class)],
            'payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
