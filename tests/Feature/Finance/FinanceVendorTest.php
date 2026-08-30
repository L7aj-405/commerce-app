<?php

declare(strict_types=1);

use App\Models\FinanceVendor;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function fvWorkspace(string $name = 'FV Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

it('creates a vendor scoped to the active organization', function (): void {
    [$owner, , $organization] = fvWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/vendors', [
        'name' => 'Acme Supplies',
        'email' => 'billing@acme.test',
    ])->assertRedirect();

    $vendor = FinanceVendor::where('name', 'Acme Supplies')->firstOrFail();
    expect($vendor->organization_id)->toBe($organization->id);
});

it('never leaks another organization\'s vendors and rejects cross-tenant updates', function (): void {
    [$ownerA, , $orgA] = fvWorkspace('FV Org A');
    [$ownerB, , $orgB] = fvWorkspace('FV Org B');

    $vendorA = FinanceVendor::create(['organization_id' => $orgA->id, 'name' => 'Vendor A']);
    FinanceVendor::create(['organization_id' => $orgB->id, 'name' => 'Vendor B']);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/vendors')->assertOk();
    $names = collect($response->viewData('page')['props']['vendors'])->pluck('name');

    expect($names)->toContain('Vendor A')->and($names)->not->toContain('Vendor B');

    $this->actingAs($ownerB)
        ->patch("/dashboard/finance/vendors/{$vendorA->id}", ['name' => 'Hijacked'])
        ->assertStatus(404);
});

it('deactivates a vendor already referenced by an expense instead of deleting it', function (): void {
    [$owner, $store, $organization] = fvWorkspace();

    $category = \App\Models\FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Other', 'slug' => 'other']);
    $vendor = FinanceVendor::create(['organization_id' => $organization->id, 'name' => 'In Use Vendor']);

    \App\Models\FinanceExpense::create([
        'organization_id' => $organization->id,
        'store_id' => $store->id,
        'category_id' => $category->id,
        'vendor_id' => $vendor->id,
        'title' => 'Something',
        'amount' => 10,
        'expense_date' => now()->toDateString(),
        'status' => 'unpaid',
    ]);

    $this->actingAs($owner)->delete("/dashboard/finance/vendors/{$vendor->id}")->assertRedirect();

    $fresh = FinanceVendor::find($vendor->id);
    expect($fresh)->not->toBeNull()->and($fresh->is_active)->toBeFalse();
});
