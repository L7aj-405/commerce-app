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
use App\Services\Finance\FinanceMonthlyStatementService;
use App\Services\OrganizationProvisioner;
use App\Services\Payroll\EmployeeAdvanceService;
use App\Services\Payroll\PayrollService;

/** @return array{0: User, 1: Store, 2: Organization} */
function fpyWorkspace(string $name = 'Finance Payroll Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    return [$owner, $store, $organization];
}

function fpyEmployee(Organization $organization, ?Store $store, float $baseSalary = 3000): Employee
{
    $employee = Employee::create([
        'organization_id' => $organization->id, 'store_id' => $store?->id,
        'first_name' => 'Paid', 'last_name' => 'Employee', 'display_name' => 'Paid Employee',
        'role_type' => 'confirmation_agent', 'employment_status' => 'active',
    ]);

    \App\Models\EmployeeSalaryProfile::create([
        'organization_id' => $organization->id, 'employee_id' => $employee->id,
        'salary_type' => 'monthly', 'base_salary' => $baseSalary, 'currency' => 'MAD',
        'payment_frequency' => 'monthly', 'effective_from' => now()->subMonth()->toDateString(), 'is_active' => true,
    ]);

    return $employee->fresh();
}

it('creates a salary_paid transaction when a payroll item is paid', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    fpyEmployee($organization, $store, 3000);
    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $item = $period->items()->first();
    $service->approvePeriod($period, $owner);

    $this->actingAs($owner)->post("/dashboard/finance/payroll/items/{$item->id}/pay", ['account_id' => $account->id])->assertSessionHasNoErrors();

    $tx = FinanceTransaction::where('source_type', PayrollItem::class)->where('source_id', $item->id)->where('type', 'salary_paid')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->direction->value)->toBe('out')
        ->and((float) $tx->amount)->toBe(3000.0)
        ->and($tx->account_id)->toBe($account->id);

    expect($period->fresh()->status->value)->toBe('paid');
});

it('never duplicates the salary_paid transaction on a repeated payment attempt', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    fpyEmployee($organization, $store);
    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $item = $period->items()->first();
    $service->approvePeriod($period, $owner);

    $service->pay($item->fresh(), $owner, $account->id);
    $service->pay($item->fresh(), $owner, $account->id);
    $service->pay($item->fresh(), $owner, $account->id);

    expect(FinanceTransaction::where('source_type', PayrollItem::class)->where('source_id', $item->id)->where('type', 'salary_paid')->count())->toBe(1);
});

it('reverses a paid payroll item on cancellation instead of deleting the transaction', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    fpyEmployee($organization, $store, 2500);
    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $item = $period->items()->first();
    $service->approvePeriod($period, $owner);
    $service->pay($item->fresh(), $owner, $account->id);

    $paidTx = FinanceTransaction::where('source_type', PayrollItem::class)->where('source_id', $item->id)->where('type', 'salary_paid')->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/payroll/items/{$item->id}/cancel", ['reason' => 'Error'])->assertSessionHasNoErrors();

    // The original transaction is untouched — never deleted.
    expect(FinanceTransaction::find($paidTx->id))->not->toBeNull();

    $reversal = FinanceTransaction::where('source_type', PayrollItem::class)->where('source_id', $item->id)->where('type', 'salary_payment_reversed')->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->direction->value)->toBe('in')
        ->and((float) $reversal->amount)->toBe(2500.0);

    expect($item->fresh()->status->value)->toBe('cancelled');
});

it('never creates a transaction just from creating an advance request', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    $employee = fpyEmployee($organization, $store);

    $this->actingAs($owner)->post("/dashboard/employees/{$employee->id}/advances", [
        'amount' => 500, 'advance_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect(FinanceTransaction::where('type', 'employee_advance_paid')->exists())->toBeFalse();
});

it('creates an employee_advance_paid transaction only when the advance is actually paid', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    $employee = fpyEmployee($organization, $store);
    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $advance = EmployeeAdvance::create([
        'organization_id' => $organization->id, 'employee_id' => $employee->id,
        'amount' => 800, 'advance_date' => now()->toDateString(), 'status' => 'pending',
    ]);

    $this->actingAs($owner)->post("/dashboard/employee-advances/{$advance->id}/pay", ['account_id' => $account->id])->assertSessionHasNoErrors();

    $tx = FinanceTransaction::where('source_type', EmployeeAdvance::class)->where('source_id', $advance->id)->where('type', 'employee_advance_paid')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->direction->value)->toBe('out')
        ->and((float) $tx->amount)->toBe(800.0);
    expect($advance->fresh()->status->value)->toBe('paid');
});

it('includes salary paid and advances paid in the monthly statement cashflow', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    $employee = fpyEmployee($organization, $store, 3000);
    $account = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    \Illuminate\Support\Carbon::setTestNow('2026-08-15 10:00:00');
    try {
        $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
        $service = app(PayrollService::class);
        $service->calculate($period);
        $item = $period->items()->first();
        $service->approvePeriod($period, $owner);
        $service->pay($item->fresh(), $owner, $account->id);

        $advance = app(EmployeeAdvanceService::class)->create($employee, $owner, ['amount' => 200, 'advance_date' => now()->toDateString()]);
        app(EmployeeAdvanceService::class)->pay($advance, $owner, $account->id);

        $statement = app(FinanceMonthlyStatementService::class)->forMonth('2026-08', null, $organization);
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }

    expect($statement['cashflow']['salaries_paid']['amount'])->toBe(3000.0)
        ->and($statement['cashflow']['advances_paid']['amount'])->toBe(200.0)
        ->and($statement['payroll']['salaries_paid_this_month']['amount'])->toBe(3000.0)
        ->and($statement['payroll']['advances_paid_this_month']['amount'])->toBe(200.0);
});

it('shows unpaid payroll (salary due) separately from cashflow — never counted as cash', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    fpyEmployee($organization, $store, 3000);

    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    app(PayrollService::class)->calculate($period);

    $statement = app(FinanceMonthlyStatementService::class)->forMonth('2026-08', null, $organization);

    expect($statement['payroll']['salary_due']['amount'])->toBe(3000.0)
        ->and($statement['cashflow']['salaries_paid']['amount'])->toBe(0.0);
});

it('rejects paying a payroll item into an account from another organization', function (): void {
    [$owner, $store, $organization] = fpyWorkspace();
    fpyEmployee($organization, $store);
    [, , $orgB] = fpyWorkspace('Finance Payroll Org B');
    app(FinanceAccountService::class)->ensureSeeded($orgB);
    $foreignAccount = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'bank')->firstOrFail();

    $period = PayrollPeriod::create(['organization_id' => $organization->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);
    $service = app(PayrollService::class);
    $service->calculate($period);
    $item = $period->items()->first();
    $service->approvePeriod($period, $owner);

    $this->actingAs($owner)->post("/dashboard/finance/payroll/items/{$item->id}/pay", ['account_id' => $foreignAccount->id])
        ->assertSessionHasErrors('account_id');
});

it('never leaks another organization\'s payroll period', function (): void {
    [$ownerA] = fpyWorkspace('Payroll Isolation A');
    [, $storeB, $orgB] = fpyWorkspace('Payroll Isolation B');

    $periodB = PayrollPeriod::create(['organization_id' => $orgB->id, 'store_id' => $storeB->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft']);

    $this->actingAs($ownerA)->get("/dashboard/finance/payroll/{$periodB->id}")->assertStatus(404);
});
