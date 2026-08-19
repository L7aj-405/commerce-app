<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;

it('lets a member switch to a store they have joined', function (): void {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $member->id,
        'role'          => 'manager',
        'store_role_id' => $store->roles()->where('slug', 'manager')->first()->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    $this->actingAs($member)
        ->from('/dashboard')
        ->post('/dashboard/stores/switch', ['store_id' => $store->id])
        ->assertRedirect('/dashboard');

    expect(session('store_id'))->toBe($store->id);
});

it('forbids switching to a store the user cannot access', function (): void {
    $stranger    = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $othersStore = Store::factory()->create(); // owned by someone else

    $this->actingAs($stranger)
        ->from('/dashboard')
        ->post('/dashboard/stores/switch', ['store_id' => $othersStore->id])
        ->assertForbidden();

    expect(session('store_id'))->toBeNull();
});
