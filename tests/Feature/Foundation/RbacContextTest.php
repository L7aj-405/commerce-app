<?php

declare(strict_types=1);

use App\Models\OrganizationMember;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

it('uses the active store role instead of users.role for dashboard and POS access', function (): void {
    $owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Atlas Group');

    $managerStore = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'name' => 'Manager Store',
    ]);
    $cashierStore = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'name' => 'Cashier Store',
    ]);

    $managerStore->ensureDefaultRoles();
    $cashierStore->ensureDefaultRoles();

    // Deliberately misleading global role: operational access must ignore it.
    $member = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    StoreMember::create([
        'store_id' => $managerStore->id,
        'user_id' => $member->id,
        'role' => 'cashier', // legacy value deliberately wrong too
        'store_role_id' => $managerStore->roles()->where('slug', 'manager')->firstOrFail()->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    StoreMember::create([
        'store_id' => $cashierStore->id,
        'user_id' => $member->id,
        'role' => 'manager', // legacy value deliberately wrong too
        'store_role_id' => $cashierStore->roles()->where('slug', 'cashier')->firstOrFail()->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    app(OrganizationProvisioner::class)->ensureMember($organization, $member);

    expect($member->canAccessDashboard($managerStore))->toBeTrue()
        ->and($member->canAccessPos($managerStore))->toBeTrue()
        ->and($member->isManager($managerStore))->toBeTrue()
        ->and($member->canAccessDashboard($cashierStore))->toBeFalse()
        ->and($member->canAccessPos($cashierStore))->toBeTrue()
        ->and($member->isCashier($cashierStore))->toBeTrue();

    session()->put('store_id', $cashierStore->id);

    expect($member->getActiveStore()?->id)->toBe($cashierStore->id)
        ->and($member->accessProfileForStore($cashierStore)['roleName'])->toBe('Cashier');
});

it('lets an organization admin access every store in the workspace without store rows', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Workspace');

    $storeA = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    $storeB = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);

    $storeA->ensureDefaultRoles();
    $storeB->ensureDefaultRoles();

    $admin = User::factory()->create([
        'role' => 'cashier', // proves the global role is irrelevant
        'onboarding_completed_at' => now(),
    ]);

    app(OrganizationProvisioner::class)->ensureMember(
        $organization,
        $admin,
        OrganizationMember::ROLE_ADMIN,
    );

    $ids = $admin->accessibleStores()->pluck('id')->all();

    expect($ids)->toContain($storeA->id, $storeB->id)
        ->and($admin->isPrivilegedFor($storeA))->toBeTrue()
        ->and($admin->hasStorePermission($storeA, 'roles.manage'))->toBeTrue()
        ->and($admin->canAccessDashboard($storeA))->toBeTrue()
        ->and($admin->canAccessPos($storeA))->toBeTrue();
});

it('never downgrades an organization owner or admin when syncing a normal store member', function (): void {
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Workspace');
    $admin = User::factory()->create();

    $provisioner = app(OrganizationProvisioner::class);
    $provisioner->ensureMember($organization, $admin, OrganizationMember::ROLE_ADMIN);
    $provisioner->ensureMember($organization, $admin, OrganizationMember::ROLE_MEMBER);
    $provisioner->ensureMember($organization, $owner, OrganizationMember::ROLE_ADMIN);
    $provisioner->ensureMember($organization, $owner, OrganizationMember::ROLE_MEMBER);

    expect($admin->organizationMemberships()->where('organization_id', $organization->id)->firstOrFail()->role)
        ->toBe(OrganizationMember::ROLE_ADMIN)
        ->and($owner->organizationMemberships()->where('organization_id', $organization->id)->firstOrFail()->role)
        ->toBe(OrganizationMember::ROLE_OWNER);
});

it('does not mutate users.role when a member role changes inside one store', function (): void {
    $owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Workspace');
    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    $store->ensureDefaultRoles();

    $member = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);
    $manager = $store->roles()->where('slug', 'manager')->firstOrFail();
    $cashier = $store->roles()->where('slug', 'cashier')->firstOrFail();

    $membership = StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $member->id,
        'role' => 'manager',
        'store_role_id' => $manager->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    app(OrganizationProvisioner::class)->ensureMember($organization, $member);

    $this->actingAs($owner)
        ->withSession(['store_id' => $store->id])
        ->patch("/dashboard/team/members/{$membership->id}", [
            'name' => $member->name,
            'store_role_id' => $cashier->id,
            'is_active' => true,
        ])
        ->assertRedirect(route('dashboard.team.index'));

    expect($member->fresh()->role)->toBe('store_admin')
        ->and($membership->fresh()->store_role_id)->toBe($cashier->id)
        ->and($member->fresh()->canAccessDashboard($store))->toBeFalse()
        ->and($member->fresh()->canAccessPos($store))->toBeTrue();
});

it('accepts an invitation using the assigned store role and syncs organization membership', function (): void {
    $owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Workspace');
    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    $store->ensureDefaultRoles();

    $cashier = $store->roles()->where('slug', 'cashier')->firstOrFail();
    $existing = User::factory()->create([
        'role' => 'store_admin', // intentionally conflicts with the invitation role
        'onboarding_completed_at' => now(),
    ]);

    $invitation = StoreInvitation::create([
        'store_id' => $store->id,
        'invited_by' => $owner->id,
        'email' => $existing->email,
        'role' => 'cashier',
        'store_role_id' => $cashier->id,
        'token' => StoreInvitation::generateToken(),
        'status' => 'pending',
        'expires_at' => now()->addHour(),
    ]);

    $this->post("/invitation/{$invitation->token}")
        ->assertRedirect('/pos');

    $this->assertAuthenticatedAs($existing);
    $this->assertDatabaseHas('organization_members', [
        'organization_id' => $organization->id,
        'user_id' => $existing->id,
        'role' => OrganizationMember::ROLE_MEMBER,
        'is_active' => true,
    ]);

    expect($existing->fresh()->role)->toBe('store_admin')
        ->and($existing->fresh()->canAccessDashboard($store))->toBeFalse()
        ->and($existing->fresh()->canAccessPos($store))->toBeTrue();
});

it('does not keep organization store creator privileges after an admin is downgraded', function (): void {
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Workspace');
    $admin = User::factory()->create(['role' => 'store_admin']);

    $membership = app(OrganizationProvisioner::class)->ensureMember(
        $organization,
        $admin,
        OrganizationMember::ROLE_ADMIN,
    );

    // Legacy stores.user_id records who created the row, but organization
    // membership must remain the authority once organization_id exists.
    $store = Store::factory()->create([
        'user_id' => $admin->id,
        'organization_id' => $organization->id,
    ]);
    $store->ensureDefaultRoles();

    expect($admin->isPrivilegedFor($store))->toBeTrue();

    $membership->update(['role' => OrganizationMember::ROLE_MEMBER]);

    expect($admin->fresh()->isPrivilegedFor($store))->toBeFalse()
        ->and($admin->fresh()->hasStorePermission($store, 'roles.manage'))->toBeFalse();
});

it('treats organization membership as the outer boundary for store roles', function (): void {
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Workspace');
    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);
    $store->ensureDefaultRoles();

    $member = User::factory()->create(['role' => 'manager']);
    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $member->id,
        'role' => 'manager',
        'store_role_id' => $store->roles()->where('slug', 'manager')->firstOrFail()->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    $orgMembership = app(OrganizationProvisioner::class)->ensureMember($organization, $member);

    expect($member->hasStorePermission($store, 'orders.view'))->toBeTrue()
        ->and($member->accessibleStores()->pluck('id')->all())->toContain($store->id);

    $orgMembership->update(['is_active' => false]);

    expect($member->fresh()->storeMembershipFor($store))->toBeNull()
        ->and($member->fresh()->hasStorePermission($store, 'orders.view'))->toBeFalse()
        ->and($member->fresh()->accessibleStores()->pluck('id')->all())->not->toContain($store->id);
});
