<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Integrations Center tabs: deep links (?tab=commerce|delivery|tools) and
| the per-category permission boundary (a Manager holds
| delivery.connections.manage but not integrations.manage, and must still
| be able to open the Integrations Center to reach the Delivery tab).
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function itOwnerWorkspace(string $name = 'Integrations Tabs Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function itManager(Store $store): User
{
    $role = $store->roles()->where('name', 'Manager')->firstOrFail();
    $manager = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $manager->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $manager;
}

function itViewer(Store $store): User
{
    $role = $store->roles()->where('name', 'Viewer')->firstOrFail();
    $viewer = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $viewer->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $viewer;
}

it('defaults to the commerce tab for a store owner with no tab query param', function (): void {
    [$owner] = itOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations')
        ->assertInertia(fn ($page) => $page->where('tab', 'commerce'));
});

it('honours ?tab=delivery and ?tab=tools deep links for a store owner', function (): void {
    [$owner] = itOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations?tab=delivery')
        ->assertInertia(fn ($page) => $page->where('tab', 'delivery')->has('delivery'));

    $this->actingAs($owner)->get('/dashboard/integrations?tab=tools')
        ->assertInertia(fn ($page) => $page->where('tab', 'tools')->has('tools'));
});

it('falls back to a valid tab for a nonsense ?tab= value', function (): void {
    [$owner] = itOwnerWorkspace();

    $this->actingAs($owner)->get('/dashboard/integrations?tab=not-a-real-tab')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tab', 'commerce'));
});

it('lets a Manager (delivery.connections.manage but not integrations.manage) open the Integrations Center on the delivery tab', function (): void {
    [, $store] = itOwnerWorkspace('Manager Tabs Store');
    $manager = itManager($store);

    $this->actingAs($manager)->get('/dashboard/integrations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Integrations/Index')
            ->where('tab', 'delivery')
            ->where('can.commerce', false)
            ->where('can.delivery', true)
            ->where('commerce', [])
            ->where('tools', [])
            ->has('delivery'));
});

it('403s a user with neither integrations.manage nor delivery.connections.manage', function (): void {
    [, $store] = itOwnerWorkspace('No Access Tabs Store');
    $viewer = itViewer($store);

    $this->actingAs($viewer)->get('/dashboard/integrations')->assertForbidden();
});
