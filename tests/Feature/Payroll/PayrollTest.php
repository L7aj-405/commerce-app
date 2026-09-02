<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceAccountService;
use App\Services\OrganizationProvisioner;
use App\Services\Payroll\EmployeeAdvanceService;
use App\Services\Payroll\EmployeeSalaryService;
use App\Services\Payroll\PayrollService;

/** @return array{0: User, 1: Store, 2: Organization} */
function pyrWorkspace(string $name = 'Payroll Run Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    return [$owner, $store, $organization];
}

function pyrEmployee(Organization $organization, ?Store $store = null, float $baseSalary = 3000): Employee
{
    $employee = Employee::create([
        'organization_id' => $organization->id, 'store_id' => $store?->id,
        'first_name' => 'Test', 'last_name' => 'Employee', 'display_name' => 'Test Employee',
        'role_type' => 'packer', 'employment_status' => 'active',
    ]);

    \App\Models\EmployeeSalaryProfile::create([
        'organization_id' => $organization->id, 'employee_id' => $employee->id,
        'salary_type' => 'monthly', 'base_salary' => $baseSalary, 'currency' => 'MAD',
        'payment_frequency' => 'monthly', 'effective_from' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ]);

    return $employee->fresh();
}

it('creates a salary profile', function (): void {
    [$owner, , $organization] = pyrWorkspace();
    $employee = Employee::create([
        'organization_id' => $organization->id, 'first_name' => 'Salaried', 'display_name' => 'Salaried', 'employment_status' => 'active',
    ]);

    app(EmployeeSalaryService::class)->createProfile($employee, $owner, [
        'base_salary' => 4000, 'effective_from' => now()->toDateString(), 'payment_frequency' => 'monthly',
    ]);

    $profile = $employee->salaryProfiles()->first();
    expect((float) $profile->base_salary)->toBe(4000.0)->and($profile->is_active)->toBeTrue();
});

it('keeps salary history when changing the salary instead of overwriting it', function (): void {
    [$owner, , $organization] = pyrWorkspace();
    $employee = pyrEmployee($organization, null, 3000);

    app(EmployeeSalaryService::class)->createProfile($employee, $owner, [
        'base_salary' => 3500, 'effective_from' => now()->toDateString(), 'payment_frequency' => 'monthly',
    ]);

    // Employee::salaryProfiles() is orderByDesc('effective_from') — newest first.
    $profiles = $employee->salaryProfiles()->get();
    expect($profiles)->toHaveCount(2);
    expect((float) $profiles->first()->base_salary)->toBe(3500.0)
        ->and($profiles->first()->is_active)->toBeTrue();
    expect((float) $profiles->last()->base_salary)->toBe(3000.0)
        ->and($profiles->last()->is_active)->toBeFalse()
        ->and($profiles->last()->effective_to)->not->toBeNull();
});

it('creates a payroll period', function (): void {
    [$owner] = pyrWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/payroll', [
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
    ])->assertSessionHasNoErrors();

    expect(PayrollPeriod::where('period_start', '2026-08-01')->exists())->toBeTrue();
});

it('calculates payroll items for every active employee', function (): void {
    [$owner, $store, $organization] = pyrWorkspace();
    pyrEmployee($organization, $store, 3000);
    pyrEmployee($organization, $store, 5000);

    $period = PayrollPeriod::create([
        'organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft',
    ]);

    app(PayrollService::class)->calculate($period);

    expect($period->items()->count())->toBe(2)
        ->and((float) $period->items()->sum('net_amount'))->toBe(8000.0)
        ->and($period->fresh()->status->value)->toBe('calculated');
});

it('never creates a finance transaction just from calculating payroll', function (): void {
    [$owner, $store, $organization] = pyrWorkspace();
    pyrEmployee($organization, $store);

    $period = PayrollPeriod::create([
        'organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft',
    ]);

    app(PayrollService::class)->calculate($period);

    expect(FinanceTransaction::where('type', 'salary_paid')->exists())->toBeFalse();
});

it('does not duplicate payroll items when recalculated', function (): void {
    [$owner, $store, $organization] = pyrWorkspace();
    pyrEmployee($organization, $store);

    $period = PayrollPeriod::create([
        'organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft',
    ]);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $service->calculate($period);

    expect($period->items()->count())->toBe(1);
});

it('rejects editing a payroll item that is no longer pending', function (): void {
    [$owner, $store, $organization] = pyrWorkspace();
    $employee = pyrEmployee($organization, $store);
    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $item = $period->items()->first();
    $service->approvePeriod($period, $owner);

    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $service->pay($item->fresh(), $owner, $account->id);

    expect(fn () => $service->updateItem($item->fresh(), ['bonus_amount' => 999]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('reduces net payroll when an advance is deducted, without creating a new cash movement', function (): void {
    [$owner, $store, $organization] = pyrWorkspace();
    $employee = pyrEmployee($organization, $store, 3000);
    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $advance = app(EmployeeAdvanceService::class)->create($employee, $owner, ['amount' => 500, 'advance_date' => now()->toDateString()]);
    app(EmployeeAdvanceService::class)->pay($advance, $owner, $account->id);

    $transactionCountBefore = FinanceTransaction::count();

    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $item = $period->items()->first();

    app(EmployeeAdvanceService::class)->applyToPayrollItem($advance->fresh(), $item);

    expect((float) $item->fresh()->advance_deduction_amount)->toBe(500.0)
        ->and((float) $item->fresh()->net_amount)->toBe(2500.0)
        ->and($advance->fresh()->status->value)->toBe('deducted')
        // No NEW transaction from the deduction itself — the cash already moved when the advance was paid.
        ->and(FinanceTransaction::count())->toBe($transactionCountBefore);
});

it('rejects a user without finance.manage_payroll from calculating or paying payroll', function (): void {
    [, $store, $organization] = pyrWorkspace();
    pyrEmployee($organization, $store);
    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);

    $role = \App\Models\StoreRole::create(['store_id' => $store->id, 'name' => 'No Payroll Perm', 'permissions' => ['finance.view'], 'is_system' => false]);
    $limited = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $limited);
    \App\Models\StoreMember::create([
        'store_id' => $store->id, 'user_id' => $limited->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($limited)->post("/dashboard/finance/payroll/{$period->id}/calculate")->assertForbidden();
});
