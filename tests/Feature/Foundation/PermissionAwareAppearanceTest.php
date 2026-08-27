<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Brand appearance is store-level and gated on settings.manage — the same
| permission the rest of Store Settings already uses (Manager's default
| role does not hold it, so a Manager here stands in for "unauthorized
| staff"). Personal theme mode/density are never gated.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function paaOwnerWorkspace(string $name = 'Appearance Permission Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function paaManager(Store $store): User
{
    $role = $store->roles()->where('name', 'Manager')->firstOrFail();
    $manager = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $manager->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $manager;
}

it('lets the store owner update brand appearance', function (): void {
    [$owner] = paaOwnerWorkspace();

    $this->actingAs($owner)
        ->patch('/dashboard/settings/branding', ['primary' => '#118858', 'font' => 'inter', 'radius' => 'rounded'])
        ->assertRedirect();
});

it('forbids a Manager (no settings.manage) from updating store brand appearance', function (): void {
    [, $store] = paaOwnerWorkspace('Manager No Brand Store');
    $manager = paaManager($store);

    expect($manager->permissionsForStore($store))->not->toContain('settings.manage');

    $this->actingAs($manager)
        ->patch('/dashboard/settings/branding', ['primary' => '#4f46e5'])
        ->assertForbidden();
});

it('never exposes secrets through the brand Inertia share — only the four whitelisted fields', function (): void {
    [$owner] = paaOwnerWorkspace('Whitelisted Brand Fields Store');

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('brand', fn ($brand) => $brand
            ->hasAll(['primary', 'accent', 'font', 'radius'])
            ->etc()));
});

it('keeps the /dashboard/settings store-settings page itself gated on settings.manage (unchanged)', function (): void {
    [, $store] = paaOwnerWorkspace('Store Settings Gate Store');
    $manager = paaManager($store);

    $this->actingAs($manager)->get('/dashboard/settings')->assertForbidden();
});
