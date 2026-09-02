<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * Employees are a PAYROLL concept, deliberately separate from
 * StoreMember/User (dashboard access/permissions) — see Employee's class
 * docblock.
 *
 * @return array{0: User, 1: Store, 2: Organization}
 */
function pyWorkspace(string $name = 'Payroll Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

it('creates an employee with a linked user', function (): void {
    [$owner, $store, $organization] = pyWorkspace();
    $linkedUser = User::factory()->create(['onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $linkedUser);

    $this->actingAs($owner)->post('/dashboard/employees', [
        'first_name' => 'Rachid', 'last_name' => 'Alaoui', 'store_id' => $store->id,
        'user_id' => $linkedUser->id, 'role_type' => 'delivery_agent',
    ])->assertRedirect();

    $employee = Employee::where('first_name', 'Rachid')->firstOrFail();
    expect($employee->organization_id)->toBe($organization->id)
        ->and($employee->user_id)->toBe($linkedUser->id)
        ->and($employee->display_name)->toBe('Rachid Alaoui')
        ->and($employee->role_type->value)->toBe('delivery_agent');
});

it('creates an employee without any user login', function (): void {
    [$owner] = pyWorkspace();

    $this->actingAs($owner)->post('/dashboard/employees', [
        'first_name' => 'Fatima', 'role_type' => 'packer',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $employee = Employee::where('first_name', 'Fatima')->firstOrFail();
    expect($employee->user_id)->toBeNull()
        ->and($employee->employment_status->value)->toBe('active');
});

it('rejects linking a user from another organization', function (): void {
    [$owner] = pyWorkspace('Employee Org A');
    [, , $orgB] = pyWorkspace('Employee Org B');
    $foreignUser = User::factory()->create(['onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($orgB, $foreignUser);

    $this->actingAs($owner)->post('/dashboard/employees', [
        'first_name' => 'Hicham', 'user_id' => $foreignUser->id,
    ])->assertSessionHasErrors('user_id');

    expect(Employee::where('first_name', 'Hicham')->exists())->toBeFalse();
});

it('rejects linking a user already active-employee-linked to someone else in the same organization', function (): void {
    [$owner, , $organization] = pyWorkspace();
    $sharedUser = User::factory()->create(['onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $sharedUser);

    Employee::create([
        'organization_id' => $organization->id, 'user_id' => $sharedUser->id,
        'first_name' => 'First', 'display_name' => 'First', 'employment_status' => 'active',
    ]);

    $this->actingAs($owner)->post('/dashboard/employees', [
        'first_name' => 'Second', 'user_id' => $sharedUser->id,
    ])->assertSessionHasErrors('user_id');
});

it('never leaks another organization\'s employees', function (): void {
    [$ownerA, , $orgA] = pyWorkspace('Employee Isolation A');
    [, , $orgB] = pyWorkspace('Employee Isolation B');

    Employee::create(['organization_id' => $orgA->id, 'first_name' => 'Org A Employee', 'display_name' => 'Org A Employee', 'employment_status' => 'active']);
    $employeeB = Employee::create(['organization_id' => $orgB->id, 'first_name' => 'Org B Employee', 'display_name' => 'Org B Employee', 'employment_status' => 'active']);

    $response = $this->actingAs($ownerA)->get('/dashboard/employees')->assertOk();
    $names = collect($response->viewData('page')['props']['employees']['data'])->pluck('display_name');

    expect($names)->toContain('Org A Employee')->not->toContain('Org B Employee');
    $this->actingAs($ownerA)->get("/dashboard/employees/{$employeeB->id}/edit")->assertStatus(404);
});

it('rejects a user without employees.manage from creating an employee', function (): void {
    [, $store, $organization] = pyWorkspace();

    $role = \App\Models\StoreRole::create(['store_id' => $store->id, 'name' => 'No Employees Perm', 'permissions' => ['finance.view'], 'is_system' => false]);
    $limited = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $limited);
    \App\Models\StoreMember::create([
        'store_id' => $store->id, 'user_id' => $limited->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($limited)->post('/dashboard/employees', ['first_name' => 'Blocked'])->assertForbidden();
});
