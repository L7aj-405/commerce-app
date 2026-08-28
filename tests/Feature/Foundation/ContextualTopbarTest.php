<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/*
|--------------------------------------------------------------------------
| Contextual topbar tabs replace the old fixed Dashboard/Orders/Products/
| Stock/Integrations center nav (a duplicate of the icon rail) with tabs
| scoped to the current module. Every href in the config must be a REAL,
| loadable page — never a dead link — and the dashboard root must show the
| command search bar instead of tabs.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function ctntWorkspace(string $name = 'Contextual Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ctntConfigSource(): string
{
    return file_get_contents(resource_path('js/Support/contextualNav.js'));
}

/** @return array<int, string> Unique pathnames (query strings stripped) referenced by every tab. */
function ctntHrefs(): array
{
    preg_match_all("/href: '([^']+)'/", ctntConfigSource(), $matches);

    return collect($matches[1])
        ->map(fn (string $href) => strtok($href, '?'))
        ->unique()
        ->values()
        ->all();
}

it('never lists the dashboard root as a contextual-tabs match (it shows the search bar instead)', function (): void {
    $source = ctntConfigSource();

    expect($source)->not->toContain("match: '/dashboard',");
});

it('references only real routes — every contextual tab href resolves for a fully-permissioned user', function (): void {
    [$owner] = ctntWorkspace();

    foreach (ctntHrefs() as $href) {
        $this->actingAs($owner)->get($href)->assertOk();
    }
});

it('keeps the resolver filtering tabs by permission and collapsing a lone tab to nothing', function (): void {
    $source = file_get_contents(resource_path('js/Support/contextualNav.js'));

    expect($source)->toContain('export function resolveContextualTabs')
        ->toContain('tabs.length > 1 ? tabs : []');
});
