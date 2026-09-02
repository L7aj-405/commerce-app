<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeSalaryProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Salary is HISTORY, never a mutable "current amount" field — see
 * EmployeeSalaryProfile's class docblock. createProfile() is the only
 * write path: it closes whatever profile is currently active (effective_to
 * = the day before the new one starts) and inserts a new active row. A
 * salary change is always a NEW row, never an update to base_salary on an
 * existing one.
 */
class EmployeeSalaryService
{
    public function createProfile(Employee $employee, User $createdBy, array $data): EmployeeSalaryProfile
    {
        $effectiveFrom = CarbonImmutable::parse($data['effective_from']);

        return DB::transaction(function () use ($employee, $createdBy, $data, $effectiveFrom) {
            $current = $employee->salaryProfiles()->where('is_active', true)->first();

            if ($current !== null) {
                $current->update([
                    'is_active' => false,
                    // Never let a backdated new profile leave an overlapping
                    // gap-less history inconsistent — the old one always ends
                    // the day before the new one starts.
                    'effective_to' => $current->effective_to !== null
                        ? $current->effective_to
                        : $effectiveFrom->subDay()->toDateString(),
                ]);
            }

            return EmployeeSalaryProfile::query()->create([
                'organization_id' => $employee->organization_id,
                'employee_id' => $employee->id,
                'salary_type' => $data['salary_type'] ?? 'monthly',
                'base_salary' => $data['base_salary'] ?? 0,
                'currency' => $data['currency'] ?? 'MAD',
                'payment_frequency' => $data['payment_frequency'] ?? 'monthly',
                'payment_day' => $data['payment_day'] ?? null,
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy->id,
            ]);
        });
    }

    /** The salary profile actually in force on a given date — used by payroll calculation, never assumes "the active one" is necessarily the right one for a backdated period. */
    public function profileAsOf(Employee $employee, CarbonImmutable $date): ?EmployeeSalaryProfile
    {
        return $employee->salaryProfiles()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->orderByDesc('effective_from')
            ->first();
    }
}
