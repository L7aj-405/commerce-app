<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Unified Appearance page (/settings/appearance): theme mode + density are
| personal, client-only preferences reachable by any authenticated user;
| the Brand & Store Appearance section is gated to settings.manage. Same
| file-source-inspection pattern as AdminOperationsNavigationClarityTest —
| the permission gate lives in client React, not server-rendered markup.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function apstWorkspace(string $name = 'Appearance Settings Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function apstCashier(Store $store): User
{
    $role = $store->roles()->where('name', 'Cashier')->firstOrFail();
    $cashier = User::factory()->create(['role' => 'member', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $cashier->id, 'role' => 'member',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $cashier;
}

function apstPageSource(): string
{
    return file_get_contents(resource_path('js/Pages/Settings/Appearance.jsx'));
}

it('loads the Appearance page for the store owner', function (): void {
    [$owner] = apstWorkspace();

    $this->actingAs($owner)->get('/settings/appearance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Appearance'));
});

it('loads the Appearance page for a member with no settings.manage permission (personal theme mode stays reachable)', function (): void {
    [, $store] = apstWorkspace('No-Brand-Access Store');
    $cashier = apstCashier($store);

    expect($cashier->permissionsForStore($store))->not->toContain('settings.manage');

    $this->actingAs($cashier)->get('/settings/appearance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Appearance'));
});

it('gates the Brand & Store Appearance section on settings.manage in the client source', function (): void {
    $source = apstPageSource();

    expect($source)->toContain('settings.manage')
        ->toContain('canManageBrand')
        ->toContain('Brand & Store Appearance')
        ->toContain('managed by your store owner or admin');
});

it('keeps theme mode and density as personal, client-only preferences', function (): void {
    $source = apstPageSource();

    expect($source)->toContain("from '@/Hooks/useTheme'")
        ->toContain("from '@/Hooks/useDensity'")
        ->toContain('Density');
});
