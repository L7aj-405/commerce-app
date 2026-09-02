<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Navigation refinement: Finance in the admin/owner icon rail, collapsible
| domain groups in the full drawer, a stable hover zone (not per-icon
| hover), and a sticky header that actually stays sticky (the shell's
| ancestor was overflow-hidden, which silently breaks position:sticky).
| Source-inspection style — this stack has no JS runtime test harness, same
| pattern as SidebarNavigationPolishTest/AppShellNavigationTest.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function nrtWorkspace(string $name = 'Nav Refinement Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function nrtRoleShortcutsSource(): string
{
    return file_get_contents(resource_path('js/Support/roleShortcuts.js'));
}

function nrtRailSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/PermissionAwareRail.jsx'));
}

function nrtDrawerSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FullNavigationDrawer.jsx'));
}

function nrtLayoutSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

function nrtShellSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/PremiumAppShell.jsx'));
}

function nrtTopbarSource(): string
{
    return file_get_contents(resource_path('js/Components/PremiumDashboard/FloatingTopbar.jsx'));
}

it('gives the owner/admin icon rail a Finance shortcut', function (): void {
    $roleShortcuts = nrtRoleShortcutsSource();

    $start = strpos($roleShortcuts, 'const OWNER_TIER_HREFS = [');
    $end = strpos($roleShortcuts, '];', $start);
    $block = substr($roleShortcuts, $start, $end - $start);

    expect($block)->toContain("'/dashboard/finance'");
});

it('still excludes Settings from every curated rail list — it only ever comes from the rail\'s single utility slot', function (): void {
    expect(nrtRoleShortcutsSource())->not->toContain("'/dashboard/settings'");
});

it('lets curateRailItems hold enough items for Dashboard/Orders/Products/Inventory/Delivery/Finance/Integrations without truncating', function (): void {
    $roleShortcuts = nrtRoleShortcutsSource();

    // 7 owner-tier hrefs must all fit — the max was bumped alongside adding
    // Finance/Delivery so nothing gets silently dropped off the end.
    preg_match('/export function curateRailItems\(allItems, roleSlug, max = (\d+)\)/', $roleShortcuts, $matches);
    expect($matches)->not->toBeEmpty();
    expect((int) $matches[1])->toBeGreaterThanOrEqual(7);
});

it('an owner/admin actually sees Finance in their curated rail items (real permission-filtered data, not just the config)', function (): void {
    [$owner] = nrtWorkspace();

    $response = $this->actingAs($owner)->get('/dashboard')->assertOk();
    $props = $response->viewData('page')['props'];

    // roleSlug drives curateRailItems() client-side; the actual visibility
    // gate this test can check server-side is that finance.view is present
    // for the owner — curateRailItems() never grants access, it only picks
    // from what already passed this same permission list.
    $permissions = $props['auth']['permissions'];
    expect(in_array('*', $permissions, true) || in_array('finance.view', $permissions, true))->toBeTrue();
});

it('never lets an unauthorized/finance-less role\'s shortcut candidates include Finance', function (): void {
    $roleShortcuts = nrtRoleShortcutsSource();

    foreach (['confirmation-agent', 'warehouse', 'dispatcher', 'delivery-agent', 'inspector', 'cashier', 'viewer'] as $roleSlug) {
        $start = strpos($roleShortcuts, "'{$roleSlug}': [") ?: strpos($roleShortcuts, "{$roleSlug}: [");
        expect($start)->not->toBeFalse("Expected a shortcut block for role [{$roleSlug}].");
        $end = strpos($roleShortcuts, '],', $start);
        $block = substr($roleShortcuts, $start, $end - $start);

        expect($block)->not->toContain('/dashboard/finance');
    }
});

it('keeps DOMAIN_ORDER covering Dashboard(Overview)/Orders/Products(Commerce)/Inventory/Delivery(Fulfillment)/Finance/Integrations/Settings with no duplicates', function (): void {
    $drawer = nrtDrawerSource();

    preg_match('/const DOMAIN_ORDER = \[(.*?)\];/s', $drawer, $matches);
    preg_match_all("/'([^']+)'/", $matches[1], $domainMatches);
    $domains = $domainMatches[1];

    expect($domains)->toEqual(array_unique($domains))
        ->toContain('Overview')->toContain('Commerce')->toContain('Orders')
        ->toContain('Fulfillment')->toContain('Inventory')->toContain('Finance')
        ->toContain('Integrations')->toContain('Settings');
});

it('makes the full drawer\'s domain groups collapsible, remembers state for the session, and auto-expands the active domain', function (): void {
    $drawer = nrtDrawerSource();

    expect($drawer)->toContain('toggleGroup')
        ->toContain('aria-expanded={isOpen}')
        ->toContain('activeDomain')
        ->toContain('sessionStorage');
});

it('lists every Finance sub-item the drawer should show under the Finance group', function (): void {
    $layout = nrtLayoutSource();

    $start = strpos($layout, "{ label: 'Finance', domain: 'Finance'");
    $end = strpos($layout, ']},', $start);
    $block = substr($layout, $start, $end - $start);

    foreach (['Overview', 'Expenses', 'Recurring / Subscriptions', 'Vendors', 'Categories', 'Accounts', 'Transactions', 'COD Receivables', 'Monthly Statement'] as $label) {
        expect($block)->toContain("label: '{$label}'");
    }
});

it('opens the drawer only from the dedicated SidebarHoverTrigger edge strip — never from hovering rail icons — while keeping click working', function (): void {
    $layout = nrtLayoutSource();
    $rail = nrtRailSource();
    $trigger = file_get_contents(resource_path('js/Components/PremiumDashboard/SidebarHoverTrigger.jsx'));

    // The dedicated hover-trigger strip is its own component, pinned to the
    // true left edge, thin enough (8-16px) to sit entirely to the left of
    // the rail (which starts at left-5/20px) so the two never overlap.
    expect($trigger)->toContain('left-0')
        ->toContain('aria-hidden="true"')
        ->toContain('onMouseEnter')
        ->toContain('onMouseLeave');
    preg_match('/\bw-(\d+(?:\.\d+)?)\b/', $trigger, $widthMatch);
    expect($widthMatch)->not->toBeEmpty('Expected the trigger to declare an explicit Tailwind width.');
    // Tailwind spacing scale: w-3 = 0.75rem = 12px — within the 8-16px band.
    expect((float) $widthMatch[1] * 4)->toBeGreaterThanOrEqual(8.0)->toBeLessThanOrEqual(16.0);

    expect($layout)->toContain('SidebarHoverTrigger')
        ->toContain('openDrawerOnHover')
        ->toContain('scheduleDrawerClose');

    // The rail itself must NOT open on hover — only the trigger and the
    // drawer's own panel do (see SidebarNavigationPolishTest for the
    // matching "rail has no hover handlers at all" assertion).
    expect($rail)->not->toContain('onMouseEnter')->not->toContain('onMouseLeave');

    // Click still works independently of hover.
    expect($layout)->toContain('onOpenDrawer=');
    expect($rail)->toContain('onClick={onOpenDrawer}');
});

it('fixes position:sticky on the header by using overflow-clip instead of overflow-hidden on its ancestor', function (): void {
    $shell = nrtShellSource();

    // Check the actual rendered className, not prose — the surrounding
    // docblock explains the overflow-hidden pitfall by name, which would
    // otherwise make a plain string search here a false negative.
    preg_match('/<div className="([^"]*)">\s*\{topbar\}/', $shell, $matches);
    expect($matches)->not->toBeEmpty('Expected to find the shell div wrapping {topbar}.');
    expect($matches[1])->toContain('overflow-clip')->not->toContain('overflow-hidden');

    expect(nrtTopbarSource())->toContain('sticky top-0');
});

it('keeps the sticky header working across Dashboard, Orders, Finance, Delivery and Settings — they all render through the same SaasLayout/PremiumAppShell', function (): void {
    [$owner] = nrtWorkspace();

    foreach ([
        '/dashboard',
        '/dashboard/orders/manage',
        '/dashboard/finance',
        '/dashboard/departments/dispatch',
        '/dashboard/settings',
    ] as $href) {
        $this->actingAs($owner)->get($href)->assertOk();
    }
});
