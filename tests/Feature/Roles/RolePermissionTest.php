<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;

/**
 * Helper: an onboarded store owner + their store with default roles seeded.
 *
 * @return array{0: User, 1: Store}
 */
function ownerWithStore(): array
{
    $user = User::factory()->create([
        'role'                    => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $store = Store::factory()->create(['user_id' => $user->id]);
    $store->ensureDefaultRoles();

    return [$user, $store];
}

it('seeds the four default system roles for a store', function (): void {
    [, $store] = ownerWithStore();

    expect($store->roles()->pluck('slug')->all())
        ->toContain('administrator', 'manager', 'cashier', 'viewer');
});

it('lets the store owner view the roles page', function (): void {
    [$owner] = ownerWithStore();

    $this->actingAs($owner)
        ->get('/dashboard/roles')
        ->assertOk();
});

it('lets an admin create a custom role with selected permissions', function (): void {
    [$owner, $store] = ownerWithStore();

    $this->actingAs($owner)
        ->post('/dashboard/roles', [
            'name'        => 'Warehouse Staff',
            'description' => 'Stock handling only',
            'permissions' => ['products.view', 'stock.view', 'stock.adjust'],
        ])
        ->assertRedirect(route('dashboard.roles.index'));

    $role = StoreRole::where('store_id', $store->id)->where('name', 'Warehouse Staff')->first();

    expect($role)->not->toBeNull()
        ->and($role->permissions)->toEqual(['products.view', 'stock.view', 'stock.adjust'])
        ->and($role->is_system)->toBeFalse();
});

it('rejects permissions that are not in the catalog', function (): void {
    [$owner] = ownerWithStore();

    $this->actingAs($owner)
        ->post('/dashboard/roles', [
            'name'        => 'Bad Role',
            'permissions' => ['made.up.permission'],
        ])
        ->assertSessionHasErrors('permissions.0');
});

it('refuses to delete a system role', function (): void {
    [$owner, $store] = ownerWithStore();
    $admin = $store->adminRole();

    $this->actingAs($owner)
        ->delete("/dashboard/roles/{$admin->id}")
        ->assertSessionHasErrors('role');

    expect(StoreRole::find($admin->id))->not->toBeNull();
});

it('gives owners every permission but members only what their role grants', function (): void {
    [$owner, $store] = ownerWithStore();

    $viewer = $store->roles()->where('slug', 'viewer')->first();

    $member = User::factory()->create(['role' => 'manager']);
    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $member->id,
        'role'          => 'manager',
        'store_role_id' => $viewer->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    expect($owner->hasStorePermission($store, 'integrations.manage'))->toBeTrue()
        ->and($member->hasStorePermission($store, 'products.view'))->toBeTrue()
        ->and($member->hasStorePermission($store, 'products.manage'))->toBeFalse()
        ->and($member->hasStorePermission($store, 'team.manage'))->toBeFalse();
});
