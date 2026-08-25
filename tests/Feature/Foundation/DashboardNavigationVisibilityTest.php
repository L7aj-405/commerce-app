<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

/** @return array{0: User, 1: Store} */
function navWorkspace(string $name = 'Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function navMemberWithRole(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);

    // `role` is a legacy enum (store_admin|manager|cashier only) — the real
    // permission source is store_role_id, same pattern RbacContextTest uses.
    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $member->id,
        'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

it('gives the store owner permissions covering products, inventory, orders and operations navigation', function (): void {
    [$owner] = navWorkspace();

    // The owner is privileged, not a StoreMember with the Administrator role,
    // so permissionsForStore() returns the full enumerated permission list
    // (PermissionCatalog::keys()) rather than the literal wildcard string.
    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);

            return $perms->contains('products.manage')
                && $perms->contains('warehouses.manage')
                && $perms->contains('orders.fulfil')
                && $perms->contains('roles.manage')
                && $perms->contains('integrations.manage');
        }));
});

it('shows a Manager the commerce/inventory nav items but hides team, roles, settings and integrations', function (): void {
    [, $store] = navWorkspace('Manager Nav Store');
    $manager = navMemberWithRole($store, 'manager');

    $this->actingAs($manager)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);
            $has = fn (string $p) => $perms->contains($p);

            return $has('products.view') && $has('products.manage') && $has('stock.view') && $has('orders.view')
                && ! $has('team.manage') && ! $has('roles.manage') && ! $has('settings.manage')
                && ! $has('integrations.manage') && ! $has('warehouses.manage');
        }));
});

it('shows a Warehouse operator the operations nav items but hides product management', function (): void {
    [, $store] = navWorkspace('Warehouse Nav Store');
    $operator = navMemberWithRole($store, 'warehouse');

    $this->actingAs($operator)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);
            $has = fn (string $p) => $perms->contains($p);

            return $has('orders.fulfil') && $has('inventory.transfers.receive') && $has('stock.view')
                && ! $has('products.manage') && ! $has('roles.manage');
        }));
});

it('routes every merchant nav destination to a real, named route', function (): void {
    $routeNames = [
        'dashboard',
        'dashboard.products.index',
        'dashboard.orders.manage',
        'dashboard.stock.index',
        'dashboard.warehouses.index',
        'dashboard.departments.confirmation',
        'dashboard.departments.packing',
        'dashboard.departments.dispatch',
        'dashboard.orders.returns.index',
        'dashboard.operations.waiting-stock',
        'dashboard.operations.picking',
        'dashboard.operations.packing',
        'dashboard.operations.ready-delivery',
        'dashboard.operations.transfers.index',
        'dashboard.team.index',
        'dashboard.roles.index',
        'dashboard.stores.index',
        'dashboard.settings.index',
        'dashboard.integrations.index',
    ];

    foreach ($routeNames as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }
});

it('lets an authorized operator reach the operations nav destinations they have permission for', function (): void {
    [, $store] = navWorkspace('Operations Reach Store');
    $operator = navMemberWithRole($store, 'warehouse');

    $this->actingAs($operator)->get('/dashboard/operations/waiting-stock')->assertOk();
    $this->actingAs($operator)->get('/dashboard/operations/picking')->assertOk();
    $this->actingAs($operator)->get('/dashboard/operations/packing')->assertOk();
    $this->actingAs($operator)->get('/dashboard/operations/ready-delivery')->assertOk();
    $this->actingAs($operator)->get('/dashboard/operations/transfers')->assertOk();
});

it('blocks a Manager (no orders.fulfil) from the operations queue pages', function (): void {
    [, $store] = navWorkspace('Manager Blocked Store');
    $manager = navMemberWithRole($store, 'manager');

    $this->actingAs($manager)->get('/dashboard/operations/picking')->assertForbidden();
});

it('blocks a Manager (no roles.manage) from the roles management page', function (): void {
    [, $store] = navWorkspace('Manager Roles Blocked Store');
    $manager = navMemberWithRole($store, 'manager');

    $this->actingAs($manager)->get('/dashboard/roles')->assertForbidden();
});
