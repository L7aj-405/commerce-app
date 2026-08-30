<?php

declare(strict_types=1);

use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\Finance\FinanceTransactionService;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function financeTxWorkspace(string $name = 'FT Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

it('is idempotent for the same source_type/source_id/type triple', function (): void {
    [, , $organization] = financeTxWorkspace();
    $service = app(FinanceTransactionService::class);

    $attributes = [
        'organization_id' => $organization->id,
        'direction' => FinanceTransactionDirection::In,
        'type' => FinanceTransactionType::PaymentCollected,
        'amount' => 100,
        'occurred_at' => now(),
        'source_type' => 'TestSource',
        'source_id' => 'source-1',
    ];

    $first = $service->record($attributes);
    $second = $service->record($attributes);
    $third = $service->record(array_merge($attributes, ['amount' => 999])); // even a different amount must not create a second row

    expect($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and(FinanceTransaction::where('source_type', 'TestSource')->where('source_id', 'source-1')->count())->toBe(1);
});

it('never deduplicates manual adjustments — each one is a distinct row', function (): void {
    [, , $organization] = financeTxWorkspace();
    $service = app(FinanceTransactionService::class);

    $service->record([
        'organization_id' => $organization->id, 'direction' => FinanceTransactionDirection::In,
        'type' => FinanceTransactionType::ManualAdjustment, 'amount' => 50, 'occurred_at' => now(), 'description' => 'a',
    ]);
    $service->record([
        'organization_id' => $organization->id, 'direction' => FinanceTransactionDirection::In,
        'type' => FinanceTransactionType::ManualAdjustment, 'amount' => 50, 'occurred_at' => now(), 'description' => 'b',
    ]);

    expect(FinanceTransaction::where('organization_id', $organization->id)->where('type', 'manual_adjustment')->count())->toBe(2);
});

it('never leaks another organization\'s transactions', function (): void {
    [$ownerA, , $orgA] = financeTxWorkspace('FT Leak A');
    [, , $orgB] = financeTxWorkspace('FT Leak B');

    FinanceTransaction::create(['organization_id' => $orgA->id, 'direction' => 'in', 'type' => 'manual_adjustment', 'amount' => 10, 'occurred_at' => now(), 'description' => 'Org A tx']);
    FinanceTransaction::create(['organization_id' => $orgB->id, 'direction' => 'in', 'type' => 'manual_adjustment', 'amount' => 10, 'occurred_at' => now(), 'description' => 'Org B tx']);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/transactions')->assertOk();
    $descriptions = collect($response->viewData('page')['props']['transactions']['data'])->pluck('description');

    expect($descriptions)->toContain('Org A tx')->and($descriptions)->not->toContain('Org B tx');
});

it('denies viewing the ledger and creating adjustments without the right permissions', function (): void {
    [, $store, $organization] = financeTxWorkspace();

    $limitedRole = StoreRole::create([
        'store_id' => $store->id,
        'name' => 'Finance View Only',
        'permissions' => ['finance.view'], // no finance.view_reports, no finance.manage_cashflow
        'is_system' => false,
    ]);

    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $staff->id, 'role' => 'manager',
        'store_role_id' => $limitedRole->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($staff)->get('/dashboard/finance/transactions')->assertForbidden();
    $this->actingAs($staff)->post('/dashboard/finance/transactions', [
        'direction' => 'in', 'amount' => 10, 'occurred_at' => now()->toDateString(), 'description' => 'nope',
    ])->assertForbidden();
});

it('lets the owner/admin view the ledger and record a manual adjustment', function (): void {
    [$owner] = financeTxWorkspace();

    $this->actingAs($owner)->get('/dashboard/finance/transactions')->assertOk();

    $this->actingAs($owner)->post('/dashboard/finance/transactions', [
        'direction' => 'out', 'amount' => 42.5, 'occurred_at' => now()->toDateString(), 'description' => 'Bank fee correction',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(FinanceTransaction::where('description', 'Bank fee correction')->where('type', 'manual_adjustment')->exists())->toBeTrue();
});
