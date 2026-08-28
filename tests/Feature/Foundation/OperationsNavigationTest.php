<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

/** @return array{0: User, 1: Store} */
function opsNavWorkspace(string $name = 'Operations Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function opsNavMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);

    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $member->id,
        'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

it('routes every operations queue destination to a real, named route', function (): void {
    foreach ([
        'dashboard.departments.confirmation',
        'dashboard.operations.waiting-stock',
        'dashboard.operations.picking',
        'dashboard.operations.packing',
        'dashboard.operations.ready-delivery',
        'dashboard.operations.transfers.index',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }
});

it('lets a Warehouse operator reach every operations queue page', function (): void {
    [, $store] = opsNavWorkspace('Warehouse Reach Store');
    $operator = opsNavMember($store, 'warehouse');

    $this->actingAs($operator)->get('/dashboard/operations/waiting-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/WaitingForStock'));

    $this->actingAs($operator)->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/Picking'));

    $this->actingAs($operator)->get('/dashboard/operations/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/Packing'));

    $this->actingAs($operator)->get('/dashboard/operations/ready-delivery')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/ReadyForDelivery'));

    $this->actingAs($operator)->get('/dashboard/operations/transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Operations/TransferReceiving'));
});

it('lets a Confirmation agent reach the confirmation queue but not the fulfilment queues', function (): void {
    [, $store] = opsNavWorkspace('Confirmation Reach Store');
    $agent = opsNavMember($store, 'confirmation-agent');

    $this->actingAs($agent)->get('/dashboard/departments/confirmation')->assertOk();
    $this->actingAs($agent)->get('/dashboard/operations/picking')->assertForbidden();
});

it('blocks an unauthorized viewer from the operations queue pages', function (): void {
    [, $store] = opsNavWorkspace('Viewer Blocked Store');
    $viewer = opsNavMember($store, 'viewer');

    $this->actingAs($viewer)->get('/dashboard/operations/picking')->assertForbidden();
    $this->actingAs($viewer)->get('/dashboard/operations/transfers')->assertForbidden();
});

it('scopes the operations transfer receiving page to the active store context', function (): void {
    [, $store] = opsNavWorkspace('Operations Store Context Store');
    $operator = opsNavMember($store, 'warehouse');

    $this->actingAs($operator)->get('/dashboard/operations/transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfers')->has('is_agency_context'));
});
