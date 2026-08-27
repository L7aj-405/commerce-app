<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| AppShell navigation refactor: the icon rail must be a compact, vertically
| centered floating dock (not a full-height sidebar pinned high), and the
| topbar's center nav must no longer duplicate the rail's destinations.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function asntWorkspace(string $name = 'AppShell Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function asntRailSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/PermissionAwareRail.jsx'));
}

function asntDrawerSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FullNavigationDrawer.jsx'));
}

function asntTopbarSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FloatingTopbar.jsx'));
}

function asntSaasLayoutSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

it('replaces the old full-height icon rail with a compact, vertically centered floating dock', function (): void {
    $source = asntRailSource();

    expect($source)->toContain('top-1/2')->toContain('-translate-y-1/2');

    // The old pinned-high, near-full-height positioning must be gone.
    expect($source)->not->toContain('top-[6.5rem]')
        ->not->toContain('h-[calc(100vh-9rem)]');
});

it('deletes the old IconRailSidebar component in favor of PermissionAwareRail + FullNavigationDrawer', function (): void {
    expect(file_exists(resource_path('js/Components/PremiumDashboard/IconRailSidebar.jsx')))->toBeFalse();
    expect(file_exists(resource_path('js/Components/PremiumDashboard/PermissionAwareRail.jsx')))->toBeTrue();
    expect(file_exists(resource_path('js/Components/PremiumDashboard/FullNavigationDrawer.jsx')))->toBeTrue();
});

it('removes the duplicated fixed primary-nav list from the topbar and SaasLayout', function (): void {
    $topbar = asntTopbarSource();
    $layout = asntSaasLayoutSource();

    expect($layout)->not->toContain('TOP_PATHS')->not->toContain('topLinks');
    expect($topbar)->not->toContain('PRIMARY_PATHS');
    expect($topbar)->toContain('ContextualModuleNav')->toContain('CommandSearchBar');
});

it('groups the full navigation drawer by domain without renaming any existing section/item label', function (): void {
    $drawer = asntDrawerSource();
    $layout = asntSaasLayoutSource();

    expect($drawer)->toContain('Overview')->toContain('Commerce')->toContain('Orders')
        ->toContain('Fulfillment')->toContain('Inventory')->toContain('Integrations')->toContain('Settings');

    // Regression-tested literal labels (AdminOperationsNavigationClarityTest,
    // IntegrationNavigationTest) must still be present verbatim.
    expect($layout)->toContain("label: 'Fulfillment Workboards'")
        ->toContain("label: 'Supervisor Queues'")
        ->toContain("label: 'Integrations'")
        ->not->toContain("label: 'Delivery providers'");
});

it('still lets an organization owner load the dashboard through the refactored shell', function (): void {
    [$owner] = asntWorkspace();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
});
