<?php

declare(strict_types=1);

use App\Models\CashierAccount;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * @return array{0: User, 1: Store}
 */
function editTeamOwner(): array
{
    $user = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $user->id]);
    $store->ensureDefaultRoles();

    return [$user, $store];
}

function makeMember(Store $store, string $slug, array $userAttrs = []): array
{
    $role   = $store->roles()->where('slug', $slug)->first();
    $user   = User::factory()->create(array_merge(['role' => 'cashier'], $userAttrs));
    $member = StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $user->id,
        'role'          => 'cashier',
        'store_role_id' => $role->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    return [$user, $member, $role];
}

it('updates a member name and role', function (): void {
    [$owner, $store] = editTeamOwner();
    [$user, $member] = makeMember($store, 'cashier');
    $viewer = $store->roles()->where('slug', 'viewer')->first();

    $this->actingAs($owner)
        ->patch("/dashboard/team/members/{$member->id}", [
            'name'          => 'Renamed Person',
            'store_role_id' => $viewer->id,
            'is_active'     => true,
        ])
        ->assertRedirect(route('dashboard.team.index'));

    expect($user->fresh()->name)->toBe('Renamed Person')
        ->and($member->fresh()->store_role_id)->toBe($viewer->id);
});

it('sets a cashier PIN and creates the cashier account', function (): void {
    [$owner, $store] = editTeamOwner();
    [$user, $member, $cashierRole] = makeMember($store, 'cashier');

    $this->actingAs($owner)
        ->patch("/dashboard/team/members/{$member->id}", [
            'name'          => $user->name,
            'store_role_id' => $cashierRole->id,
            'is_active'     => true,
            'pin_code'      => '1234',
        ])
        ->assertRedirect(route('dashboard.team.index'));

    $cashier = CashierAccount::where('store_id', $store->id)->where('user_id', $user->id)->first();

    expect($cashier)->not->toBeNull()
        ->and($cashier->status)->toBe('active')
        ->and(Hash::check('1234', $cashier->pin_code))->toBeTrue();
});

it('validates the PIN is exactly 4 digits', function (): void {
    [$owner, $store] = editTeamOwner();
    [$user, $member, $cashierRole] = makeMember($store, 'cashier');

    $this->actingAs($owner)
        ->patch("/dashboard/team/members/{$member->id}", [
            'name'          => $user->name,
            'store_role_id' => $cashierRole->id,
            'pin_code'      => '12',
        ])
        ->assertSessionHasErrors('pin_code');
});

it('deactivates the cashier account when POS access is removed', function (): void {
    [$owner, $store] = editTeamOwner();
    [$user, $member, $cashierRole] = makeMember($store, 'cashier');

    CashierAccount::create([
        'store_id' => $store->id,
        'user_id'  => $user->id,
        'pin_code' => '1234',
        'status'   => 'active',
    ]);

    $viewer = $store->roles()->where('slug', 'viewer')->first(); // no pos.access

    $this->actingAs($owner)
        ->patch("/dashboard/team/members/{$member->id}", [
            'name'          => $user->name,
            'store_role_id' => $viewer->id,
            'is_active'     => true,
        ])
        ->assertRedirect(route('dashboard.team.index'));

    expect(CashierAccount::where('user_id', $user->id)->first()->status)->toBe('inactive');
});

it('logs in the correct cashier when a store has several', function (): void {
    [, $store] = editTeamOwner();
    [$userA] = makeMember($store, 'cashier');
    [$userB] = makeMember($store, 'cashier');

    CashierAccount::create(['store_id' => $store->id, 'user_id' => $userA->id, 'pin_code' => '1111', 'status' => 'active']);
    CashierAccount::create(['store_id' => $store->id, 'user_id' => $userB->id, 'pin_code' => '2222', 'status' => 'active']);

    $this->post('/pos/login', ['pin_code' => '2222', 'store_id' => $store->id])
        ->assertRedirect('/pos');

    $this->assertAuthenticatedAs($userB->fresh());
});

it('rejects a wrong PIN', function (): void {
    [, $store] = editTeamOwner();
    [$userA] = makeMember($store, 'cashier');
    CashierAccount::create(['store_id' => $store->id, 'user_id' => $userA->id, 'pin_code' => '1111', 'status' => 'active']);

    $this->post('/pos/login', ['pin_code' => '9999', 'store_id' => $store->id])
        ->assertSessionHasErrors('pin_code');

    $this->assertGuest();
});
