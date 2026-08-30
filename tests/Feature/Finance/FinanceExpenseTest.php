<?php

declare(strict_types=1);

use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceVendor;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: FinanceExpenseCategory, 4: FinanceVendor}
 */
function feWorkspace(string $name = 'FE Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Software / Apps', 'slug' => 'software-apps']);
    $vendor = FinanceVendor::create(['organization_id' => $organization->id, 'name' => 'Cloud Co']);

    return [$owner, $store, $organization, $category, $vendor];
}

it('creates an expense as unpaid by default', function (): void {
    [$owner, $store, $organization, $category, $vendor] = feWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Server hosting',
        'amount' => 199.99,
        'category_id' => $category->id,
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'expense_date' => now()->toDateString(),
    ])->assertRedirect();

    $expense = FinanceExpense::where('title', 'Server hosting')->firstOrFail();

    expect($expense->organization_id)->toBe($organization->id)
        ->and((float) $expense->amount)->toBe(199.99)
        ->and($expense->status->value)->toBe('unpaid')
        ->and($expense->created_by)->toBe($owner->id);
});

it('edits an expense', function (): void {
    [$owner, , , $category] = feWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Old title',
        'amount' => 50,
        'expense_date' => now()->toDateString(),
        'status' => 'unpaid',
    ]);

    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => 'New title',
        'amount' => 75,
        'category_id' => $category->id,
        'expense_date' => now()->toDateString(),
    ])->assertRedirect();

    $expense->refresh();
    expect($expense->title)->toBe('New title')->and((float) $expense->amount)->toBe(75.0);
});

it('marks an unpaid expense as paid and back to unpaid', function (): void {
    [$owner, , , $category] = feWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Ads',
        'amount' => 300,
        'expense_date' => now()->toDateString(),
        'status' => 'unpaid',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'card'])->assertRedirect();
    $expense->refresh();
    expect($expense->status->value)->toBe('paid')->and($expense->paid_at)->not->toBeNull();

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid")->assertRedirect();
    $expense->refresh();
    expect($expense->status->value)->toBe('unpaid')->and($expense->paid_at)->toBeNull();
});

it('filters expenses by status, category, store and date range', function (): void {
    [$owner, $store, $organization, $category] = feWorkspace();
    $otherCategory = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Rent', 'slug' => 'rent']);

    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id, 'title' => 'Match', 'amount' => 10, 'expense_date' => '2026-03-10', 'status' => 'paid']);
    FinanceExpense::create(['organization_id' => $organization->id, 'category_id' => $otherCategory->id, 'title' => 'NoMatchCategory', 'amount' => 10, 'expense_date' => '2026-03-10', 'status' => 'paid']);
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id, 'title' => 'NoMatchStatus', 'amount' => 10, 'expense_date' => '2026-03-10', 'status' => 'unpaid']);
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id, 'title' => 'NoMatchDate', 'amount' => 10, 'expense_date' => '2026-01-01', 'status' => 'paid']);

    $response = $this->actingAs($owner)->get('/dashboard/finance/expenses?' . http_build_query([
        'status' => 'paid',
        'category_id' => $category->id,
        'store_id' => $store->id,
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ]))->assertOk();

    $titles = collect($response->viewData('page')['props']['expenses']['data'])->pluck('title');

    expect($titles)->toContain('Match')
        ->and($titles)->not->toContain('NoMatchCategory')
        ->and($titles)->not->toContain('NoMatchStatus')
        ->and($titles)->not->toContain('NoMatchDate');
});

it('never leaks another organization\'s expenses', function (): void {
    [$ownerA, , $orgA, $categoryA] = feWorkspace('FE Org A');
    [, , $orgB, $categoryB] = feWorkspace('FE Org B');

    FinanceExpense::create(['organization_id' => $orgA->id, 'category_id' => $categoryA->id, 'title' => 'Org A Expense', 'amount' => 10, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);
    $expenseB = FinanceExpense::create(['organization_id' => $orgB->id, 'category_id' => $categoryB->id, 'title' => 'Org B Expense', 'amount' => 10, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/expenses')->assertOk();
    $titles = collect($response->viewData('page')['props']['expenses']['data'])->pluck('title');

    expect($titles)->toContain('Org A Expense')->and($titles)->not->toContain('Org B Expense');

    $this->actingAs($ownerA)->get("/dashboard/finance/expenses/{$expenseB->id}/edit")->assertStatus(404);
});

it('rejects a category or vendor id that belongs to another organization', function (): void {
    [$ownerA, , , $categoryA] = feWorkspace('FE Reject A');
    [, , $orgB] = feWorkspace('FE Reject B');

    $categoryB = FinanceExpenseCategory::create(['organization_id' => $orgB->id, 'name' => 'Foreign Category', 'slug' => 'foreign-category']);
    $vendorB = FinanceVendor::create(['organization_id' => $orgB->id, 'name' => 'Foreign Vendor']);

    $this->actingAs($ownerA)->post('/dashboard/finance/expenses', [
        'title' => 'Should fail',
        'amount' => 10,
        'category_id' => $categoryB->id,
        'expense_date' => now()->toDateString(),
    ])->assertSessionHasErrors('category_id');

    $this->actingAs($ownerA)->post('/dashboard/finance/expenses', [
        'title' => 'Should also fail',
        'amount' => 10,
        'category_id' => $categoryA->id, // valid category, but foreign vendor
        'vendor_id' => $vendorB->id,
        'expense_date' => now()->toDateString(),
    ])->assertSessionHasErrors('vendor_id');
});
