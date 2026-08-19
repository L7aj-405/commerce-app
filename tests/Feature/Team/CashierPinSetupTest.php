<?php

declare(strict_types=1);

use App\Models\CashierAccount;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * A store with a member on the given role. Factory users have password "password".
 *
 * @return array{0: Store, 1: User}
 */
function posMember(string $slug = 'cashier'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $user = User::factory()->create(['role' => 'cashier']);
    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $user->id,
        'role'          => 'cashier',
        'store_role_id' => $store->roles()->where('slug', $slug)->first()->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    return [$store, $user];
}

it('lets a first-time cashier enrol their own PIN', function (): void {
    [$store, $user] = posMember('cashier');

    $this->post('/pos/setup-pin', [
        'store_id'              => $store->id,
        'email'                 => $user->email,
        'password'              => 'password',
        'pin_code'              => '4321',
        'pin_code_confirmation' => '4321',
    ])->assertRedirect('/pos');

    $this->assertAuthenticatedAs($user->fresh());

    $cashier = CashierAccount::where('store_id', $store->id)->where('user_id', $user->id)->first();
    expect($cashier)->not->toBeNull()
        ->and($cashier->status)->toBe('active')
        ->and(Hash::check('4321', $cashier->pin_code))->toBeTrue();
});

it('rejects enrolment with a wrong password', function (): void {
    [$store, $user] = posMember('cashier');

    $this->post('/pos/setup-pin', [
        'store_id'              => $store->id,
        'email'                 => $user->email,
        'password'              => 'wrong-password',
        'pin_code'              => '4321',
        'pin_code_confirmation' => '4321',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(CashierAccount::where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects enrolment for a member without POS access', function (): void {
    [$store, $user] = posMember('viewer'); // viewer has no pos.access

    $this->post('/pos/setup-pin', [
        'store_id'              => $store->id,
        'email'                 => $user->email,
        'password'              => 'password',
        'pin_code'              => '4321',
        'pin_code_confirmation' => '4321',
    ])->assertSessionHasErrors('email');

    expect(CashierAccount::where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses to overwrite an existing PIN through self-enrolment', function (): void {
    [$store, $user] = posMember('cashier');
    CashierAccount::create(['store_id' => $store->id, 'user_id' => $user->id, 'pin_code' => '1111', 'status' => 'active']);

    $this->post('/pos/setup-pin', [
        'store_id'              => $store->id,
        'email'                 => $user->email,
        'password'              => 'password',
        'pin_code'              => '4321',
        'pin_code_confirmation' => '4321',
    ])->assertSessionHasErrors('pin_code');

    // Original PIN is untouched.
    expect(Hash::check('1111', CashierAccount::where('user_id', $user->id)->first()->pin_code))->toBeTrue();
});

it('requires the PIN confirmation to match', function (): void {
    [$store, $user] = posMember('cashier');

    $this->post('/pos/setup-pin', [
        'store_id'              => $store->id,
        'email'                 => $user->email,
        'password'              => 'password',
        'pin_code'              => '4321',
        'pin_code_confirmation' => '9999',
    ])->assertSessionHasErrors('pin_code');

    expect(CashierAccount::where('user_id', $user->id)->exists())->toBeFalse();
});
