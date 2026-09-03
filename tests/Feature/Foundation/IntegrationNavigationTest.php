<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations Center navigation: the standalone "Delivery providers"
| sidebar item is gone — "Integrations" is the only sidebar entry point,
| and it stays highlighted while on the (still-existing, unmoved) Ozon
| setup/manage screen. No duplicate delivery-provider page is required to
| exist independently of the Integrations Center.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function inOwnerWorkspace(string $name = 'Integration Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function inManager(Store $store): User
{
    $role = $store->roles()->where('name', 'Manager')->firstOrFail();
    $manager = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $manager->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $manager;
}

/**
 * The sidebar's nav definitions live in this client-side file — there is no
 * server-rendered nav markup to inspect (React renders it after hydration),
 * so asserting labels/behavior means reading the source of truth directly.
 * Same pattern as AdminOperationsNavigationClarityTest.
 */
function inSidebarSource(): string
{
    return file_get_contents(resource_path('js/Layouts/SaasLayout.jsx'));
}

it('keeps a single "Integrations" sidebar item and removes the standalone "Delivery providers" item', function (): void {
    $source = inSidebarSource();

    expect($source)->toContain("label: 'Integrations'");

    // The exact array-literal form that used to define the standalone item —
    // the fix isn't complete if it only got relabelled/duplicated.
    expect($source)->not->toContain("label: 'Delivery providers'");
});

it('highlights the Integrations sidebar item while on the Ozon setup/manage screen', function (): void {
    $source = inSidebarSource();

    // Current navigation no longer uses the old isNavItemActive() helper.
    // The contract is that the Integrations sidebar item includes the Ozon
    // setup/manage route in its activeOn paths, so the Integrations domain
    // stays highlighted while that legacy setup page is open.
    expect($source)
        ->toContain("label: 'Integrations'")
        ->toContain("activeOn: ['/dashboard/delivery-connections']");

    expect($source)->not->toContain('function isNavItemActive(');
});

it('routes the Integrations Center and the underlying Ozon setup page to real, named routes', function (): void {
    foreach (['dashboard.integrations.index', 'dashboard.delivery-connections.index'] as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }
});

it('shows delivery companies on the Integrations Center delivery tab', function (): void {
    [$owner] = inOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->where('tab', 'delivery')
            ->has('delivery'));
});

it('registers exactly one setup page per delivery provider, never a competing duplicate', function (): void {
    $deliveryIndexRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => $route->getName() !== null
            && str_ends_with($route->getName(), '.index')
            && str_contains($route->uri(), 'delivery'));

    // Exactly two: Ozon's and Sendit's own detail/setup pages, one per
    // provider — the Integrations Center's delivery tab is server-rendered
    // data on the shared /dashboard/integrations index, never a second
    // routed page for the SAME provider.
    expect($deliveryIndexRoutes->pluck('uri')->sort()->values()->all())
        ->toBe(['dashboard/delivery-connections', 'dashboard/delivery-connections/sendit']);
});

it('keeps the old Ozon setup route directly accessible (no sidebar entry, but not broken/removed)', function (): void {
    [$owner] = inOwnerWorkspace('Direct Route Access Store');

    // Nothing depends on this URL redirecting — the Integrations Center's
    // "Manage" button and the setup page's own "Back" link both target it
    // directly, and existing Ozon/city-mapping tests hit it as a plain GET.
    // Removing the sidebar item must not remove or redirect the route.
    $this->actingAs($owner)->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Delivery/Connections'));
});

it('uses the same tab query keys in the contextual topbar config as the Integrations Center controller accepts', function (): void {
    $config = file_get_contents(resource_path('js/Support/contextualNav.js'));

    expect($config)->toContain('/dashboard/integrations?tab=commerce')
        ->toContain('/dashboard/integrations?tab=delivery')
        ->toContain('/dashboard/integrations?tab=tools');

    // The Ozon setup screen (no sidebar entry of its own) also resolves to
    // the same contextual tabs, matching its icon-rail activeOn wiring.
    expect($config)->toContain("match: '/dashboard/delivery-connections'");
});

it('lets a Manager reach the Integrations Center and the Ozon setup page it links to, with credentials and city mapping intact', function (): void {
    [, $store] = inOwnerWorkspace('Manager Nav Store');
    $manager = inManager($store);

    $this->actingAs($manager)->get('/dashboard/integrations?tab=delivery')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')->has('delivery'));

    $this->actingAs($manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express', 'customer_id' => 'CUST-NAV', 'api_key' => 'nav-secret-key',
    ])->assertRedirect();

    $this->actingAs($manager)->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Delivery/Connections')
            ->where('connection.customer_id', 'CUST-NAV')
            ->has('mapped_cities')
            ->has('unmapped_cities'));
});
