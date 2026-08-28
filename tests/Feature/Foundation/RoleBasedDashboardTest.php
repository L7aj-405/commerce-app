<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * /dashboard renders a different page per role — see
 * DashboardController::resolveDashboardKind(). Same route, same page
 * component name; only the `dashboard_kind` prop and the props that follow
 * it differ.
 */

/** @return array{0: User, 1: Store} */
function rbdWorkspace(string $name = 'Role Dashboard Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function rbdMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

it('gives the store owner the owner dashboard with revenue stats', function (): void {
    [$owner] = rbdWorkspace();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'owner')
            ->has('stats')
            ->has('recent_orders'));
});

it('gives a confirmation agent the confirmation dashboard, not the owner view', function (): void {
    [, $store] = rbdWorkspace('CA Dashboard Store');
    $agent = rbdMember($store, 'confirmation-agent');

    $this->actingAs($agent)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'confirmation')
            ->has('waiting_count')
            ->has('claimed_by_me_count')
            ->has('points_preview')
            ->missing('stats')
            ->missing('recent_orders'));
});

it('gives a warehouse operator the fulfillment dashboard', function (): void {
    [, $store] = rbdWorkspace('Warehouse Dashboard Store');
    $operator = rbdMember($store, 'warehouse');

    $this->actingAs($operator)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'fulfillment')
            ->has('assigned_to_me_count')
            ->has('ready_for_dispatch_count')
            ->missing('stats'));
});

it('gives a dispatcher the delivery dashboard', function (): void {
    [, $store] = rbdWorkspace('Dispatcher Dashboard Store');
    $dispatcher = rbdMember($store, 'dispatcher');

    $this->actingAs($dispatcher)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'delivery')
            ->has('returns_to_inspect_count')
            ->has('today')
            ->missing('stats'));
});

it('gives a supervisor the operations control dashboard', function (): void {
    [, $store] = rbdWorkspace('Supervisor Dashboard Store');
    $supervisor = rbdMember($store, 'supervisor');

    $this->actingAs($supervisor)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'supervisor')
            ->has('operations.queues')
            ->has('operations.leaderboard')
            ->missing('stats'));
});

it('falls back to the owner dashboard for a Manager with no operational role', function (): void {
    [, $store] = rbdWorkspace('Manager Dashboard Store');
    $manager = rbdMember($store, 'manager');

    $this->actingAs($manager)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('dashboard_kind', 'owner'));
});
