<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| The compact icon rail is curated per role (Support/roleShortcuts.js) on
| top of the existing permission-filtered nav items — never a new
| authorization path. This checks the curation config covers every role the
| design brief named, and that the real per-role data (auth.access.roleSlug,
| auth.permissions) the curation logic reads is shaped the way it expects.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function pantOwnerWorkspace(string $name = 'Role Rail Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pantMember(Store $store, string $roleName): User
{
    $role = $store->roles()->where('name', $roleName)->firstOrFail();
    $member = User::factory()->create(['role' => 'member', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'member',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $member;
}

function pantRoleShortcutsSource(): string
{
    return file_get_contents(resource_path('js/Support/roleShortcuts.js'));
}

it('curates the rail for every role slug named in the design brief', function (): void {
    $source = pantRoleShortcutsSource();

    foreach ([
        'organization-owner', 'manager', 'supervisor', 'confirmation-agent',
        'warehouse', 'dispatcher', 'delivery-agent', 'inspector', 'cashier', 'viewer',
    ] as $roleSlug) {
        expect($source)->toContain("'{$roleSlug}'", "Expected roleShortcuts.js to curate the rail for role [{$roleSlug}].");
    }

    expect($source)->toContain('DEFAULT_SHORTCUT_HREFS')
        ->toContain('export function curateRailItems');
});

it('gives a confirmation agent only confirmation-desk-flavored candidate shortcuts, never Products/Settings', function (): void {
    $source = pantRoleShortcutsSource();

    $start = strpos($source, "'confirmation-agent': [");
    $end = strpos($source, '],', $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('/dashboard/departments/confirmation')
        ->not->toContain('/dashboard/products')
        ->not->toContain('/dashboard/settings');
});

it('gives a picker/packer (warehouse role) work-queue shortcuts, never confirmation or settings pages', function (): void {
    $source = pantRoleShortcutsSource();

    $start = strpos($source, 'warehouse: [');
    $end = strpos($source, '],', $start);
    $block = substr($source, $start, $end - $start);

    expect($block)->toContain('/dashboard/departments/packing')
        ->not->toContain('/dashboard/departments/confirmation')
        ->not->toContain('/dashboard/settings');
});

it('exposes a real per-store role slug for a Confirmation agent, matching what the rail curation keys on', function (): void {
    [, $store] = pantOwnerWorkspace('Confirmation Rail Store');
    $agent = pantMember($store, 'Confirmation agent');

    $this->actingAs($agent)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.access.roleSlug', 'confirmation-agent')
            ->where('auth.permissions', fn ($perms) => collect($perms)->contains('orders.confirm') && ! collect($perms)->contains('products.manage')));
});

it('exposes a real per-store role slug for a Warehouse member, matching what the rail curation keys on', function (): void {
    [, $store] = pantOwnerWorkspace('Warehouse Rail Store');
    $worker = pantMember($store, 'Warehouse');

    $this->actingAs($worker)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.access.roleSlug', 'warehouse')
            ->where('auth.permissions', fn ($perms) => collect($perms)->contains('orders.fulfil')));
});

it('exposes organization-owner as the role slug for the privileged store owner', function (): void {
    [$owner] = pantOwnerWorkspace('Owner Rail Store');

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.access.roleSlug', 'organization-owner'));
});
