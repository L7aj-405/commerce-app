<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * Regression coverage for the "Finance is not visible in the main sidebar"
 * bug: NAV_SECTIONS/contextualNav.js correctly listed Finance and its perm
 * checks, but FullNavigationDrawer.jsx's DOMAIN_ORDER — a separate, hardcoded
 * whitelist the final render pass iterates over — never had 'Finance' added
 * to it, so every Finance item was silently dropped from the drawer despite
 * passing every permission check upstream. An HTTP-only test (asserting 200
 * on /dashboard/finance) cannot catch this class of bug: the route worked
 * fine the whole time, only the drawer's static React source was wrong.
 */
function financeNavWorkspace(string $name = 'Finance Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

function financeNavDrawerSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FullNavigationDrawer.jsx'));
}

function financeNavSaasLayoutSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

function financeNavContextualNavSource(): string
{
    return file_get_contents(resource_path('js/Support/contextualNav.js'));
}

it('lists Finance in the drawer\'s DOMAIN_ORDER whitelist, not just in NAV_SECTIONS', function (): void {
    $drawer = financeNavDrawerSource();

    // The exact bug: NAV_SECTIONS/contextualNav.js can list Finance perfectly
    // and it will STILL never render, because groupByDomain()'s final pass
    // only emits domains present in this constant.
    expect($drawer)->toMatch('/const DOMAIN_ORDER = \[[^\]]*\'Finance\'[^\]]*\]/');
});

it('registers a Finance section with all six expected pages in the sidebar', function (): void {
    $layout = financeNavSaasLayoutSource();

    expect($layout)->toContain("domain: 'Finance'")
        ->and($layout)->toContain("href: '/dashboard/finance'")
        ->and($layout)->toContain("href: '/dashboard/finance/expenses'")
        ->and($layout)->toContain("href: '/dashboard/finance/recurring'")
        ->and($layout)->toContain("href: '/dashboard/finance/vendors'")
        ->and($layout)->toContain("href: '/dashboard/finance/categories'")
        ->and($layout)->toContain("href: '/dashboard/finance/statement'");
});

it('gates the Finance sidebar section on finance.view (or the wildcard), never leaving it unguarded', function (): void {
    $layout = financeNavSaasLayoutSource();

    // Every item between the Finance section header and the next section
    // must carry a perm check — a copy-paste that drops `perm:` would make
    // the section visible to every authenticated user regardless of role.
    preg_match("/label: 'Finance', domain: 'Finance', items: \[(.*?)\]\},/s", $layout, $matches);
    expect($matches)->toHaveCount(2);

    $itemCount = substr_count($matches[1], 'href:');
    $permCount = substr_count($matches[1], "perm: 'finance.");
    expect($permCount)->toBe($itemCount);
});

it('registers a Finance contextual tab bar covering every Finance page', function (): void {
    $contextualNav = financeNavContextualNavSource();

    expect($contextualNav)->toContain("match: '/dashboard/finance'")
        ->and($contextualNav)->toContain("href: '/dashboard/finance/expenses'")
        ->and($contextualNav)->toContain("href: '/dashboard/finance/recurring'")
        ->and($contextualNav)->toContain("href: '/dashboard/finance/vendors'")
        ->and($contextualNav)->toContain("href: '/dashboard/finance/categories'")
        ->and($contextualNav)->toContain("href: '/dashboard/finance/statement'");
});

it('every Finance route name referenced anywhere in the app actually exists', function (): void {
    $names = [
        'dashboard.finance.dashboard',
        'dashboard.finance.expenses.index',
        'dashboard.finance.expenses.create',
        'dashboard.finance.expenses.store',
        'dashboard.finance.expenses.edit',
        'dashboard.finance.expenses.update',
        'dashboard.finance.recurring.index',
        'dashboard.finance.recurring.create',
        'dashboard.finance.vendors.index',
        'dashboard.finance.categories.index',
        'dashboard.finance.statement.index',
    ];

    foreach ($names as $name) {
        expect(\Illuminate\Support\Facades\Route::has($name))->toBeTrue("Route [{$name}] is not registered.");
    }
});

it('exposes finance.view in auth.permissions for the owner, so the sidebar item renders', function (): void {
    [$owner] = financeNavWorkspace();

    $response = $this->actingAs($owner)->get('/dashboard')->assertOk();
    $permissions = $response->viewData('page')['props']['auth']['permissions'];

    // A privileged (owner/admin) user gets the full expanded catalogue from
    // User::permissionsForStore() — never the literal '*' wildcard, which is
    // only ever a StoreRole storage shorthand, expanded server-side before it
    // reaches the frontend. finance.view must be present either way.
    expect($permissions)->toContain('finance.view');
});

it('never exposes finance.view in auth.permissions for a staff member without it', function (): void {
    [, $store, $organization] = financeNavWorkspace();

    $limitedRole = StoreRole::create([
        'store_id' => $store->id,
        'name' => 'Warehouse Only',
        'permissions' => ['products.view', 'stock.view'],
        'is_system' => false,
    ]);

    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $staff->id,
        'role' => 'manager',
        'store_role_id' => $limitedRole->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    $response = $this->actingAs($staff)->get('/dashboard')->assertOk();
    $permissions = $response->viewData('page')['props']['auth']['permissions'];

    expect($permissions)->not->toContain('finance.view');
});
