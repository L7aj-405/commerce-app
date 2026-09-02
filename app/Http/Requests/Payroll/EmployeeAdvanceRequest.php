<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Models\EmployeeAdvance;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EmployeeAdvance::class);
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'advance_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
