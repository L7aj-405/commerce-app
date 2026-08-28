<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * A full-page GET request that hits 403/404/419/500 renders the branded
 * Inertia 'Error' page (resources/js/Pages/Error.jsx) instead of Laravel's
 * bare framework default — see App\Support\InertiaErrorResponder. The
 * status code is always preserved exactly; only the response BODY changes,
 * so ->assertForbidden()/->assertNotFound() keep meaning exactly what they
 * always have across the rest of the test suite.
 */

/** @return array{0: User, 1: Store} */
function beptWorkspace(string $name = 'Branded Error Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function beptMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'manager',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

it('renders the branded Error page (status 403) for a full-page access violation', function (): void {
    [, $store] = beptWorkspace();
    $manager = beptMember($store, 'manager');

    $this->actingAs($manager)->get('/dashboard/roles')
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 403)
            ->has('message'));
});

it('preserves 403 as the real HTTP status code, never silently turning it into a success', function (): void {
    [, $store] = beptWorkspace('Never Silent Success Store');
    $manager = beptMember($store, 'manager');

    $response = $this->actingAs($manager)->get('/dashboard/operations/picking');

    expect($response->getStatusCode())->toBe(403);
});

it('renders the branded Error page (status 404) for a nonexistent resource', function (): void {
    [$owner] = beptWorkspace('Branded 404 Store');

    $this->actingAs($owner)->get('/dashboard/orders/online/01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));
});

it('carries the real backend authorization message onto the branded page', function (): void {
    [, $store] = beptWorkspace('Branded Message Store');
    $manager = beptMember($store, 'manager');

    $this->actingAs($manager)->get('/dashboard/roles')
        ->assertInertia(fn ($page) => $page->where('message', fn ($message) => is_string($message) && $message !== ''));
});
