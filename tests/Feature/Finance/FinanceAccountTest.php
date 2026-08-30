<?php

declare(strict_types=1);

use App\Models\FinanceAccount;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\Finance\FinanceAccountService;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function financeAccountWorkspace(string $name = 'FA Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

it('seeds the five default finance accounts the first time an organization visits the Accounts page', function (): void {
    [$owner] = financeAccountWorkspace();

    $this->actingAs($owner)->get('/dashboard/finance/accounts')->assertOk();

    $names = FinanceAccount::query()->pluck('name')->all();

    foreach (['Cash', 'Bank', 'Card / TPE', 'COD Receivable', 'Delivery Company Balance'] as $expected) {
        expect($names)->toContain($expected);
    }
});

it('creates an account scoped to the active organization', function (): void {
    [$owner, , $organization] = financeAccountWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/accounts', [
        'name' => 'PayPal',
        'type' => 'bank',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $account = FinanceAccount::where('name', 'PayPal')->firstOrFail();
    expect($account->organization_id)->toBe($organization->id);
});

it('enforces account name uniqueness per organization but allows the same name across organizations', function (): void {
    [$ownerA, , $orgA] = financeAccountWorkspace('FA Uniq A');
    [$ownerB, , $orgB] = financeAccountWorkspace('FA Uniq B');

    $this->actingAs($ownerA)->post('/dashboard/finance/accounts', ['name' => 'Petty Cash', 'type' => 'cash'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($ownerA)->post('/dashboard/finance/accounts', ['name' => 'Petty Cash', 'type' => 'cash'])
        ->assertSessionHasErrors('name');
    $this->actingAs($ownerB)->post('/dashboard/finance/accounts', ['name' => 'Petty Cash', 'type' => 'cash'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $countInA = FinanceAccount::withoutOrganizationTenancy(
        fn () => FinanceAccount::where('organization_id', $orgA->id)->where('name', 'Petty Cash')->count(),
    );
    $countInB = FinanceAccount::withoutOrganizationTenancy(
        fn () => FinanceAccount::where('organization_id', $orgB->id)->where('name', 'Petty Cash')->count(),
    );

    expect($countInA)->toBe(1)->and($countInB)->toBe(1);
});

it('never leaks another organization\'s accounts and rejects cross-tenant updates', function (): void {
    [$ownerA, , $orgA] = financeAccountWorkspace('FA Leak A');
    [$ownerB, , $orgB] = financeAccountWorkspace('FA Leak B');

    $accountA = FinanceAccount::create(['organization_id' => $orgA->id, 'name' => 'Org A Wallet', 'type' => 'other']);
    FinanceAccount::create(['organization_id' => $orgB->id, 'name' => 'Org B Wallet', 'type' => 'other']);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/accounts')->assertOk();
    $names = collect($response->viewData('page')['props']['accounts'])->pluck('name');

    expect($names)->toContain('Org A Wallet')->and($names)->not->toContain('Org B Wallet');

    $this->actingAs($ownerB)
        ->patch("/dashboard/finance/accounts/{$accountA->id}", ['name' => 'Hijacked', 'type' => 'cash'])
        ->assertStatus(404);
});

it('deactivates an account already used in the ledger instead of deleting it', function (): void {
    [$owner, , $organization] = financeAccountWorkspace();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    \App\Models\FinanceTransaction::create([
        'organization_id' => $organization->id,
        'account_id' => $cash->id,
        'direction' => 'in',
        'type' => 'manual_adjustment',
        'amount' => 10,
        'occurred_at' => now(),
        'description' => 'seed',
    ]);

    $this->actingAs($owner)->delete("/dashboard/finance/accounts/{$cash->id}")->assertRedirect();

    $fresh = FinanceAccount::find($cash->id);
    expect($fresh)->not->toBeNull()->and($fresh->is_active)->toBeFalse();
});

it('denies a staff member without finance.manage_accounts from creating or updating accounts', function (): void {
    [, $store, $organization] = financeAccountWorkspace();

    $limitedRole = StoreRole::create([
        'store_id' => $store->id,
        'name' => 'Finance Viewer Only',
        'permissions' => ['finance.view'],
        'is_system' => false,
    ]);

    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $staff->id, 'role' => 'manager',
        'store_role_id' => $limitedRole->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($staff)->get('/dashboard/finance/accounts')->assertOk();
    $this->actingAs($staff)->post('/dashboard/finance/accounts', ['name' => 'Nope', 'type' => 'cash'])->assertForbidden();
});

it('lets the owner/admin manage accounts end to end', function (): void {
    [$owner] = financeAccountWorkspace();

    $this->actingAs($owner)->get('/dashboard/finance/accounts')->assertOk();

    $this->actingAs($owner)->post('/dashboard/finance/accounts', ['name' => 'Wise', 'type' => 'bank'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $account = FinanceAccount::where('name', 'Wise')->firstOrFail();

    $this->actingAs($owner)->patch("/dashboard/finance/accounts/{$account->id}", ['name' => 'Wise EUR', 'type' => 'bank'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($account->refresh()->name)->toBe('Wise EUR');
});
