<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * An ordinary (non-admin) worker should mainly see the ONE workboard their
 * role actually does hands-on work on, never the full Supervisor Queues
 * section — that's new nav-visibility permission `operations.supervise`,
 * which a plain picker/packer role does not hold. The underlying
 * /dashboard/operations/* ROUTES stay gated exactly as before
 * (orders.fulfil / inventory.transfers.receive) — only the sidebar entry
 * changes, so nothing that previously worked by direct URL breaks.
 */

/** @return array{0: User, 1: Store} */
function workerNavWorkspace(string $name = 'Worker Nav Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function workerNavMember(Store $store, string $roleSlug): User
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

it('gives an ordinary Warehouse picker/packer the workbench permission but not the supervisor-queue nav permission', function (): void {
    [, $store] = workerNavWorkspace();
    $picker = workerNavMember($store, 'warehouse');

    $this->actingAs($picker)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);

            return $perms->contains('orders.fulfil') && ! $perms->contains('operations.supervise');
        }));
});

it('lets the picker reach the Pick & Pack Workbench', function (): void {
    [, $store] = workerNavWorkspace('Picker Workbench Store');
    $picker = workerNavMember($store, 'warehouse');

    $this->actingAs($picker)->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Departments/Packing'));
});

it('still lets the picker reach a supervisor queue page directly by URL — the route is unchanged, only the sidebar link is hidden', function (): void {
    [, $store] = workerNavWorkspace('Picker Direct Url Store');
    $picker = workerNavMember($store, 'warehouse');

    // Backward compatible: orders.fulfil (which Warehouse already holds)
    // still opens these routes exactly as before this phase.
    $this->actingAs($picker)->get('/dashboard/operations/picking')->assertOk();
    $this->actingAs($picker)->get('/dashboard/operations/packing')->assertOk();
});

it('gives a Supervisor role both the workbench and supervisor-queue permissions, and reaches every queue page', function (): void {
    [, $store] = workerNavWorkspace('Supervisor Store');
    $supervisor = workerNavMember($store, 'supervisor');

    $this->actingAs($supervisor)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);

            return $perms->contains('operations.supervise') && $perms->contains('orders.fulfil');
        }));

    foreach ([
        '/dashboard/operations/waiting-stock',
        '/dashboard/operations/picking',
        '/dashboard/operations/packing',
        '/dashboard/operations/ready-delivery',
        '/dashboard/operations/transfers',
    ] as $url) {
        $this->actingAs($supervisor)->get($url)->assertOk();
    }
});

it('gives a Confirmation agent only the Confirmation Desk permission, not the fulfillment/supervisor ones', function (): void {
    [, $store] = workerNavWorkspace('Confirmation Agent Store');
    $agent = workerNavMember($store, 'confirmation-agent');

    $this->actingAs($agent)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);

            return $perms->contains('orders.confirm')
                && ! $perms->contains('orders.fulfil')
                && ! $perms->contains('operations.supervise');
        }));

    $this->actingAs($agent)->get('/dashboard/departments/confirmation')->assertOk();
    $this->actingAs($agent)->get('/dashboard/operations/picking')->assertForbidden();
});

it('gives a Delivery agent only the deliver permission, and an Inspector only the returns/inspect permission', function (): void {
    [, $store] = workerNavWorkspace('Delivery Inspector Store');
    $driver = workerNavMember($store, 'delivery-agent');
    $inspector = workerNavMember($store, 'inspector');

    $this->actingAs($driver)->get('/dashboard/my-deliveries')->assertOk();

    $this->actingAs($inspector)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', function ($perms) {
            $perms = collect($perms);

            return $perms->contains('orders.inspect') && ! $perms->contains('operations.supervise');
        }));

    $this->actingAs($inspector)->get('/dashboard/orders/returns')->assertOk();
});
