<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Enums\EmployeeEmploymentStatus;
use App\Enums\EmployeeRoleType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee
            ? $this->user()->can('update', $employee)
            : $this->user()->can('create', Employee::class);
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();
        $employee = $this->route('employee');

        return [
            'store_id' => ['nullable', 'string', Rule::in($storeIds->all())],
            'user_id' => [
                'nullable', 'string',
                Rule::exists('users', 'id'),
            ],
            'employee_code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employees', 'employee_code')
                    ->where(fn ($q) => $q->where('organization_id', $organizationId))
                    ->ignore($employee?->id),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'role_type' => ['nullable', Rule::enum(EmployeeRoleType::class)],
            'employment_status' => ['nullable', Rule::enum(EmployeeEmploymentStatus::class)],
            'hired_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
