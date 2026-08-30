<?php

declare(strict_types=1);

use App\Models\FinanceExpenseCategory;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceExpenseCategoryService;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function fecWorkspace(string $name = 'FEC Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

it('seeds the default expense categories the first time an organization visits Finance', function (): void {
    [$owner] = fecWorkspace();

    $this->actingAs($owner)->get('/dashboard/finance/categories')->assertOk();

    $names = FinanceExpenseCategory::query()->pluck('name')->all();

    foreach (FinanceExpenseCategoryService::defaultCategoryNames() as $expected) {
        expect($names)->toContain($expected);
    }
});

it('never leaks another organization\'s categories', function (): void {
    [$ownerA, , $orgA] = fecWorkspace('FEC Org A');
    [$ownerB, , $orgB] = fecWorkspace('FEC Org B');

    $categoryA = FinanceExpenseCategory::create(['organization_id' => $orgA->id, 'name' => 'Org A Only', 'slug' => 'org-a-only']);
    FinanceExpenseCategory::create(['organization_id' => $orgB->id, 'name' => 'Org B Only', 'slug' => 'org-b-only']);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/categories')->assertOk();
    $categoryNames = collect($response->viewData('page')['props']['categories'])->pluck('name');

    expect($categoryNames)->toContain('Org A Only')
        ->and($categoryNames)->not->toContain('Org B Only');

    // Org B cannot update Org A's category by guessing its id.
    $this->actingAs($ownerB)
        ->patch("/dashboard/finance/categories/{$categoryA->id}", ['name' => 'Hijacked'])
        ->assertStatus(404);
});

it('enforces category name uniqueness per organization but allows the same name across organizations', function (): void {
    [$ownerA, , $orgA] = fecWorkspace('FEC Uniq A');
    [$ownerB, , $orgB] = fecWorkspace('FEC Uniq B');

    $this->actingAs($ownerA)->post('/dashboard/finance/categories', ['name' => 'Consulting'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
    $this->actingAs($ownerA)->post('/dashboard/finance/categories', ['name' => 'Consulting'])
        ->assertSessionHasErrors('name');

    // Same name in a different organization is fine.
    $this->actingAs($ownerB)->post('/dashboard/finance/categories', ['name' => 'Consulting'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // Raw model queries below run with no authenticated user for THIS request
    // (there is none — actingAs() only affects the app's Auth state, and the
    // last HTTP call already finished), so bypass the organization scope
    // explicitly instead of relying on auth()->user()'s leftover state.
    $countInA = FinanceExpenseCategory::withoutOrganizationTenancy(
        fn () => FinanceExpenseCategory::where('organization_id', $orgA->id)->where('name', 'Consulting')->count(),
    );
    $countInB = FinanceExpenseCategory::withoutOrganizationTenancy(
        fn () => FinanceExpenseCategory::where('organization_id', $orgB->id)->where('name', 'Consulting')->count(),
    );

    expect($countInA)->toBe(1)->and($countInB)->toBe(1);
});

it('deactivates a system or in-use category instead of hard-deleting it', function (): void {
    [$owner, $store, $organization] = fecWorkspace();
    app(FinanceExpenseCategoryService::class)->seedDefaults($organization);

    $systemCategory = FinanceExpenseCategory::where('organization_id', $organization->id)->where('is_system', true)->firstOrFail();

    $this->actingAs($owner)->delete("/dashboard/finance/categories/{$systemCategory->id}")->assertRedirect();

    expect(FinanceExpenseCategory::find($systemCategory->id))->not->toBeNull()
        ->and(FinanceExpenseCategory::find($systemCategory->id)->is_active)->toBeFalse();
});
