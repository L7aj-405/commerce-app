<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\EmployeeEmploymentStatus;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Employees are a payroll concept, deliberately separate from
 * StoreMember/User (dashboard access + permissions) — see Employee's class
 * docblock. An employee may have no login at all (`user_id` null), and
 * linking one to a user never grants or changes that user's permissions.
 */
class EmployeeService
{
    /**
     * @param  array<string, mixed>  $filters  store_id/role_type/employment_status/search
     */
    public function filteredQuery(Organization $organization, array $filters = []): Builder
    {
        return Employee::query()
            ->where('organization_id', $organization->id)
            ->with(['store:id,name', 'user:id,name,email'])
            ->when($filters['store_id'] ?? null, fn (Builder $q, $v) => $q->where('store_id', $v))
            ->when($filters['role_type'] ?? null, fn (Builder $q, $v) => $q->where('role_type', $v))
            ->when($filters['employment_status'] ?? null, fn (Builder $q, $v) => $q->where('employment_status', $v))
            ->when($filters['search'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $sub) => $sub
                ->where('display_name', 'like', "%{$v}%")
                ->orWhere('employee_code', 'like', "%{$v}%")
                ->orWhere('phone', 'like', "%{$v}%")))
            ->orderBy('display_name');
    }

    public function create(Organization $organization, User $createdBy, array $data): Employee
    {
        if (! empty($data['user_id'])) {
            $this->assertUserLinkable($organization, $data['user_id']);
        }

        return Employee::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'employee_code' => $data['employee_code'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'display_name' => $this->displayName($data),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'role_type' => $data['role_type'] ?? null,
            'employment_status' => $data['employment_status'] ?? EmployeeEmploymentStatus::Active->value,
            'hired_at' => $data['hired_at'] ?? null,
            'left_at' => $data['left_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $createdBy->id,
        ]);
    }

    public function update(Employee $employee, array $data): Employee
    {
        $newUserId = array_key_exists('user_id', $data) ? ($data['user_id'] ?: null) : $employee->user_id;

        if ($newUserId !== null && $newUserId !== $employee->user_id) {
            $this->assertUserLinkable($employee->organization, $newUserId, ignoreEmployeeId: $employee->id);
        }

        $employee->update([
            'store_id' => $data['store_id'] ?? null,
            'user_id' => $newUserId,
            'employee_code' => $data['employee_code'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'display_name' => $this->displayName($data),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'role_type' => $data['role_type'] ?? null,
            'employment_status' => $data['employment_status'] ?? $employee->employment_status->value,
            'hired_at' => $data['hired_at'] ?? null,
            'left_at' => $data['left_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $employee->refresh();
    }

    /** Attach an existing user account to an employee with no login yet — same validation as update(). */
    public function linkUser(Employee $employee, string $userId): Employee
    {
        $this->assertUserLinkable($employee->organization, $userId, ignoreEmployeeId: $employee->id);
        $employee->update(['user_id' => $userId]);

        return $employee->refresh();
    }

    public function unlinkUser(Employee $employee): Employee
    {
        $employee->update(['user_id' => null]);

        return $employee->refresh();
    }

    /**
     * A user must belong to the SAME organization to be linked, and (unless
     * explicitly allowed by the caller — no UI path does today, this is
     * purely a safety valve per the product spec's "unless intentionally
     * allowed") must not already be the active employee behind another
     * ACTIVE employee record in this organization. Deliberately an
     * application-level check, not a DB constraint, so an already-left
     * employee record never blocks re-linking that person later.
     */
    private function assertUserLinkable(Organization $organization, string $userId, ?string $ignoreEmployeeId = null): void
    {
        $user = User::withoutTrashed()->find($userId);

        if ($user === null || ! $user->organizations()->where('organizations.id', $organization->id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'That user does not belong to this organization.',
            ]);
        }

        $alreadyLinked = Employee::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $userId)
            ->where('employment_status', EmployeeEmploymentStatus::Active->value)
            ->when($ignoreEmployeeId, fn (Builder $q, $v) => $q->where('id', '!=', $v))
            ->exists();

        if ($alreadyLinked) {
            throw ValidationException::withMessages([
                'user_id' => 'This user is already linked to another active employee in this organization.',
            ]);
        }
    }

    private function displayName(array $data): string
    {
        if (! empty($data['display_name'])) {
            return $data['display_name'];
        }

        return trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
    }
}
