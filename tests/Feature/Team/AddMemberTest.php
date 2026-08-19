<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;

/**
 * @return array{0: User, 1: Store}
 */
function teamOwner(): array
{
    $user = User::factory()->create([
        'role'                    => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $store = Store::factory()->create(['user_id' => $user->id]);
    $store->ensureDefaultRoles();

    return [$user, $store];
}

it('lets an admin create and add a brand-new user to the team', function (): void {
    [$owner, $store] = teamOwner();
    $manager = $store->roles()->where('slug', 'manager')->first();

    $this->actingAs($owner)
        ->post('/dashboard/team/add', [
            'name'                  => 'Jane Doe',
            'email'                 => 'jane@example.com',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'store_role_id'         => $manager->id,
        ])
        ->assertRedirect(route('dashboard.team.index'));

    $user = User::where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe('manager')
        ->and($user->onboarding_completed_at)->not->toBeNull();

    $member = StoreMember::where('store_id', $store->id)->where('user_id', $user->id)->first();
    expect($member)->not->toBeNull()
        ->and($member->store_role_id)->toBe($manager->id);
});

it('requires a password when creating a new user', function (): void {
    [$owner, $store] = teamOwner();
    $manager = $store->roles()->where('slug', 'manager')->first();

    $this->actingAs($owner)
        ->post('/dashboard/team/add', [
            'name'          => 'No Password',
            'email'         => 'nopass@example.com',
            'store_role_id' => $manager->id,
        ])
        ->assertSessionHasErrors('password');

    expect(User::where('email', 'nopass@example.com')->exists())->toBeFalse();
});

it('attaches an existing user without creating a new account', function (): void {
    [$owner, $store] = teamOwner();
    $cashier  = $store->roles()->where('slug', 'cashier')->first();
    $existing = User::factory()->create(['email' => 'existing@example.com', 'role' => 'store_admin']);

    $countBefore = User::count();

    $this->actingAs($owner)
        ->post('/dashboard/team/add', [
            'name'          => 'Existing Person',
            'email'         => 'existing@example.com',
            'store_role_id' => $cashier->id,
        ])
        ->assertRedirect(route('dashboard.team.index'));

    expect(User::count())->toBe($countBefore)                       // no new user
        ->and($existing->fresh()->role)->toBe('store_admin');       // global role untouched

    expect(StoreMember::where('store_id', $store->id)->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('blocks adding someone who is already on the team', function (): void {
    [$owner, $store] = teamOwner();
    $manager = $store->roles()->where('slug', 'manager')->first();
    $member  = User::factory()->create(['email' => 'member@example.com']);

    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $member->id,
        'role'          => 'manager',
        'store_role_id' => $manager->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    $this->actingAs($owner)
        ->post('/dashboard/team/add', [
            'name'          => 'Member Again',
            'email'         => 'member@example.com',
            'store_role_id' => $manager->id,
        ])
        ->assertSessionHasErrors('email');
});

it('resolves the joined store as the active store for a non-owner member', function (): void {
    [, $store] = teamOwner();
    $manager = $store->roles()->where('slug', 'manager')->first();
    $member  = User::factory()->create(['role' => 'manager']);

    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $member->id,
        'role'          => 'manager',
        'store_role_id' => $manager->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    // The member owns no store, but must still resolve the joined one.
    expect($member->getActiveStore())->not->toBeNull()
        ->and($member->getActiveStore()->id)->toBe($store->id)
        ->and($member->hasStorePermission($store, 'orders.view'))->toBeTrue()
        ->and($member->hasStorePermission($store, 'settings.manage'))->toBeFalse();
});
