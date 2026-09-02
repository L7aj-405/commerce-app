<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Sidebar/navigation UI polish: no duplicated icon (Settings appeared twice
| — once via the curated rail items, once via the fixed utility slot), a
| hover-to-expand path into the full navigation drawer alongside the
| existing click, and a real custom Select/listbox replacing the ugliest
| native <select> spots. Source-inspection style, matching
| AppShellNavigationTest/PermissionAwareNavigationTest — this stack has no
| JS runtime test harness, so the React source itself is the asserted
| contract, same as those existing files.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function snptWorkspace(string $name = 'Sidebar Polish Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function snptRailSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/PermissionAwareRail.jsx'));
}

function snptDrawerSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FullNavigationDrawer.jsx'));
}

function snptSaasLayoutSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

function snptRoleShortcutsSource(): string
{
    return file_get_contents(resource_path('js/Support/roleShortcuts.js'));
}

function snptSelectSource(): string
{
    return file_get_contents(resource_path('js/Components/Select.jsx'));
}

it('never lets the Settings shortcut appear in a curated role list — it only ever comes from the rail\'s single utility slot', function (): void {
    $roleShortcuts = snptRoleShortcutsSource();

    // Every candidate href list in ROLE_SHORTCUT_HREFS / OWNER_TIER_HREFS /
    // DEFAULT_SHORTCUT_HREFS must exclude '/dashboard/settings' — Settings
    // has exactly one home in the rail: the `utilityItem` slot rendered by
    // PermissionAwareRail, never a second entry from a curated list.
    expect($roleShortcuts)->not->toContain("'/dashboard/settings'");
});

it('has the rail defensively filter the utility item\'s own href out of the curated items list', function (): void {
    $rail = snptRailSource();

    expect($rail)->toContain('utilityItem.href')
        ->toContain('filter');
});

it('gives the rail\'s utility (Settings) icon the same active-state styling as every other rail icon', function (): void {
    $rail = snptRailSource();

    // Both the curated items AND the utility item must resolve their
    // highlighted state through the same isActive()/isItemActive() helpers
    // — not a plain always-muted class for the utility slot.
    expect(substr_count($rail, 'bg-primary-soft text-primary'))->toBeGreaterThanOrEqual(2);
    expect($rail)->toContain('isActive(currentUrl, utilityItem.href)');
});

it('supports hover-to-expand via the dedicated trigger and the drawer itself — never the icon rail, so hovering icons cannot pop the drawer open on top of them', function (): void {
    $rail = snptRailSource();
    $drawer = snptDrawerSource();
    $layout = snptSaasLayoutSource();

    // The rail must NOT wire hover to opening the drawer — an earlier
    // version did, and hovering a rail icon on the way to clicking it could
    // pop the drawer open and interfere with the click. See
    // NavigationRefinementTest for the dedicated SidebarHoverTrigger this
    // was replaced with.
    expect($rail)->not->toContain('onMouseEnter')->not->toContain('onMouseLeave');

    // The drawer's own panel still accepts hover handlers (moving the
    // cursor into the open drawer keeps it open)...
    expect($drawer)->toContain('onMouseEnter')->toContain('onMouseLeave');

    // ...wired from SaasLayout with a delayed close (avoids flicker) and
    // click still working via onOpenDrawer.
    expect($layout)->toContain('onMouseEnter={openDrawerOnHover}')
        ->toContain('onMouseLeave={scheduleDrawerClose}')
        ->toContain('setTimeout')
        ->toContain('onOpenDrawer=');
});

it('keeps Finance in the drawer\'s domain order with no duplicated domain entries', function (): void {
    $drawer = snptDrawerSource();

    preg_match('/const DOMAIN_ORDER = \[(.*?)\];/s', $drawer, $matches);
    expect($matches)->not->toBeEmpty();

    preg_match_all("/'([^']+)'/", $matches[1], $domainMatches);
    $domains = $domainMatches[1];

    expect($domains)->toContain('Finance')
        ->toContain('Settings')
        ->toContain('Fulfillment')
        ->toContain('Inventory')
        ->toContain('Integrations');
    expect($domains)->toEqual(array_unique($domains), 'DOMAIN_ORDER must not list the same domain twice.');
});

it('replaces the native <select> with a real custom listbox component (role="listbox"/"option", keyboard support)', function (): void {
    $select = snptSelectSource();

    expect($select)->not->toContain('<select')
        ->toContain('role="listbox"')
        ->toContain('role="option"')
        ->toContain("case 'ArrowDown'")
        ->toContain("case 'ArrowUp'")
        ->toContain("case 'Enter'")
        ->toContain("case 'Escape'");
});

it('uses the custom Select for the dashboard date/period picker instead of a native <select>', function (): void {
    $dashboard = file_get_contents(resource_path('js/Components/Dashboard/Roles/OwnerDashboard.jsx'));

    expect($dashboard)->toContain("from '@/Components/Select'")
        ->not->toContain('<select');
});

it('lets an organization owner load the dashboard, with every curated rail destination resolving (no 404s)', function (): void {
    [$owner] = snptWorkspace();

    $this->actingAs($owner)->get('/dashboard')->assertOk();

    // Mirrors OWNER_TIER_HREFS in Support/roleShortcuts.js — if a future
    // edit renames/removes one of these routes without updating the rail
    // config, this catches it as a 404 rather than a silently dead icon.
    foreach (['/dashboard', '/dashboard/orders/manage', '/dashboard/products', '/dashboard/stock', '/dashboard/integrations', '/dashboard/settings'] as $href) {
        $this->actingAs($owner)->get($href)->assertStatus(200);
    }
});
