<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function financeAccessWorkspace(string $name = 'Finance Access Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

/**
 * A store-scoped StoreMember is not enough once a Store carries an
 * organization_id: Store::organization()->hasActiveMember() also checks
 * OrganizationMember, so the staff user needs both rows.
 */
function financeAddStaffWithRole(Store $store, Organization $organization, StoreRole $role): User
{
    // users.role is a DB-level enum (super_admin|store_admin|manager|cashier) — the
    // Finance permission check itself comes entirely from the StoreRole/StoreMember
    // permissions below, never from users.role, so any valid enum value works here.
    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);

    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);

    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $staff->id,
        'role' => 'manager',
        'store_role_id' => $role->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    return $staff;
}

it('lets the owner/admin access the Finance dashboard and every Finance page', function (): void {
    [$owner] = financeAccessWorkspace();

    $this->actingAs($owner)->get('/dashboard/finance')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/expenses')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/expenses/create')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/recurring')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/recurring/create')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/vendors')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/categories')->assertOk();
    $this->actingAs($owner)->get('/dashboard/finance/statement')->assertOk();
});

it('denies a staff member without finance permissions', function (): void {
    [, $store, $organization] = financeAccessWorkspace();

    $limitedRole = StoreRole::create([
        'store_id' => $store->id,
        'name' => 'Warehouse Only',
        'permissions' => ['products.view', 'stock.view'],
        'is_system' => false,
    ]);

    $staff = financeAddStaffWithRole($store, $organization, $limitedRole);

    $this->actingAs($staff)->get('/dashboard/finance')->assertForbidden();
    $this->actingAs($staff)->get('/dashboard/finance/expenses')->assertForbidden();
});

it('lets a staff member with only finance.view see read pages but not manage anything', function (): void {
    [, $store, $organization] = financeAccessWorkspace();

    $viewerRole = StoreRole::create([
        'store_id' => $store->id,
        'name' => 'Finance Viewer',
        'permissions' => ['finance.view'],
        'is_system' => false,
    ]);

    $staff = financeAddStaffWithRole($store, $organization, $viewerRole);

    $this->actingAs($staff)->get('/dashboard/finance')->assertOk();
    $this->actingAs($staff)->get('/dashboard/finance/expenses')->assertOk();

    // No finance.manage_expenses — creating is blocked at the route middleware.
    $this->actingAs($staff)->get('/dashboard/finance/expenses/create')->assertForbidden();
    $this->actingAs($staff)->post('/dashboard/finance/expenses', [])->assertForbidden();

    // No finance.view_reports — the monthly statement stays out of reach.
    $this->actingAs($staff)->get('/dashboard/finance/statement')->assertForbidden();
});
