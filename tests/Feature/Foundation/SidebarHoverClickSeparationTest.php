<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Sidebar hover vs click separation: hovering a rail icon must never open
| the full navigation drawer (it was interfering with clicking the icon) —
| only the dedicated SidebarHoverTrigger edge strip and the open drawer's
| own panel may open/keep it open on hover. Source-inspection style, same
| pattern as the existing navigation tests in this suite.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function shcsWorkspace(string $name = 'Hover Click Separation Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function shcsRailSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/PermissionAwareRail.jsx'));
}

function shcsTriggerSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/SidebarHoverTrigger.jsx'));
}

function shcsLayoutSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

function shcsRoleShortcutsSource(): string
{
    return file_get_contents(resource_path('js/Support/roleShortcuts.js'));
}

it('gives navigation icon <Link>s in the rail no drawer hover-open handler at all', function (): void {
    $rail = shcsRailSource();

    // The rail's icon-rendering <Link> block itself carries no mouse
    // handlers — only className/href/aria-label — so hovering an icon can
    // only ever trigger its own CSS :hover styling, never a JS side effect
    // that opens the drawer.
    preg_match('/railItems\.map\(\(item\) => \{.*?\}\)\}/s', $rail, $matches);
    expect($matches)->not->toBeEmpty('Expected to find the rail items .map() block.');
    expect($matches[0])->not->toContain('onMouseEnter')->not->toContain('onMouseLeave');

    // And the <aside> itself has none either — belt and suspenders.
    expect($rail)->not->toContain('onMouseEnter')->not->toContain('onMouseLeave');
});

it('has a dedicated SidebarHoverTrigger component as the only far-left hover-open surface', function (): void {
    expect(file_exists(resource_path('js/Components/PremiumDashboard/SidebarHoverTrigger.jsx')))->toBeTrue();

    $trigger = shcsTriggerSource();
    expect($trigger)->toContain('left-0')
        ->toContain('fixed')
        ->toContain('onMouseEnter')
        ->toContain('onMouseLeave');

    // No click/navigation of its own — purely a hover target.
    expect($trigger)->not->toContain('<Link')->not->toContain('onClick');
});

it('wires SidebarHoverTrigger and the drawer\'s own panel to a shared delayed close, so leaving both is required before it closes', function (): void {
    $layout = shcsLayoutSource();

    expect($layout)->toContain('SidebarHoverTrigger')
        ->toContain('scheduleDrawerClose')
        ->toContain('setTimeout')
        ->toContain('clearCloseTimer');
});

it('still opens/pins the drawer on a click of the launcher button, independent of hover', function (): void {
    $rail = shcsRailSource();

    expect($rail)->toContain('onClick={onOpenDrawer}')
        ->toContain("aria-label=\"Open all navigation\"");
});

it('never shows Settings twice in the rail', function (): void {
    $rail = shcsRailSource();

    // Same defensive filter as before — utilityItem's href is excluded
    // from the curated items list before rendering.
    expect($rail)->toContain('utilityItem.href')
        ->toContain('filter');
    expect(shcsRoleShortcutsSource())->not->toContain("'/dashboard/settings'");
});

it('keeps Finance in the owner/admin rail shortcut candidates', function (): void {
    $roleShortcuts = shcsRoleShortcutsSource();
    $start = strpos($roleShortcuts, 'const OWNER_TIER_HREFS = [');
    $end = strpos($roleShortcuts, '];', $start);

    expect(substr($roleShortcuts, $start, $end - $start))->toContain("'/dashboard/finance'");
});

it('resolves every route a curated owner/admin rail icon links to, and lets the sticky header keep working', function (): void {
    [$owner] = shcsWorkspace();

    foreach ([
        '/dashboard',
        '/dashboard/orders/manage',
        '/dashboard/products',
        '/dashboard/stock',
        '/dashboard/departments/dispatch',
        '/dashboard/finance',
        '/dashboard/integrations',
        '/dashboard/settings',
    ] as $href) {
        $this->actingAs($owner)->get($href)->assertOk();
    }

    // Sticky header regression guard — see NavigationRefinementTest for the
    // full overflow-clip explanation.
    expect(file_get_contents(resource_path('js/Components/PremiumDashboard/PremiumAppShell.jsx')))->toContain('overflow-clip');
});
