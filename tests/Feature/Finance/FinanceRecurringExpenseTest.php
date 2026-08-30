<?php

declare(strict_types=1);

use App\Enums\FinanceRecurringStatus;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceRecurringExpense;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceRecurringExpenseService;
use App\Services\OrganizationProvisioner;
use Carbon\CarbonImmutable;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: FinanceExpenseCategory}
 */
function freWorkspace(string $name = 'FRE Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Domain / Hosting', 'slug' => 'domain-hosting']);

    return [$owner, $store, $organization, $category];
}

it('creates a recurring expense via the dashboard', function (): void {
    [$owner, $store, $organization, $category] = freWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/recurring', [
        'title' => 'insolea.com domain renewal',
        'category_id' => $category->id,
        'amount' => 120,
        'frequency' => 'yearly',
        'starts_at' => '2026-08-29',
        'next_due_at' => '2027-08-29',
        'reminder_days_before' => 15,
        'auto_create_expense' => true,
    ])->assertRedirect();

    $recurring = FinanceRecurringExpense::where('title', 'insolea.com domain renewal')->firstOrFail();

    expect($recurring->organization_id)->toBe($organization->id)
        ->and($recurring->status)->toBe(FinanceRecurringStatus::Active)
        ->and($recurring->frequency->value)->toBe('yearly');
});

it('generates a due expense and advances next_due_at by one period', function (): void {
    [, , $organization, $category] = freWorkspace();

    $recurring = app(\App\Services\Finance\FinanceRecurringExpenseService::class)->create($organization, [
        'title' => 'Hosting',
        'category_id' => $category->id,
        'amount' => 50,
        'frequency' => 'monthly',
        'starts_at' => '2026-01-01',
        'next_due_at' => '2026-08-29',
    ]);

    $summary = app(FinanceRecurringExpenseService::class)->generateDue(CarbonImmutable::parse('2026-08-29'));

    expect($summary['generated'])->toBe(1);

    $recurring->refresh();
    expect($recurring->next_due_at->toDateString())->toBe('2026-09-29')
        ->and($recurring->last_generated_at)->not->toBeNull();

    $expense = FinanceExpense::where('recurring_expense_id', $recurring->id)->firstOrFail();
    expect($expense->expense_date->toDateString())->toBe('2026-08-29')
        ->and($expense->status->value)->toBe('unpaid')
        ->and((float) $expense->amount)->toBe(50.0);
});

it('is idempotent — running generation twice for the same due date never creates a duplicate expense', function (): void {
    [, , $organization, $category] = freWorkspace();

    $recurring = app(FinanceRecurringExpenseService::class)->create($organization, [
        'title' => 'Software subscription',
        'category_id' => $category->id,
        'amount' => 30,
        'frequency' => 'monthly',
        'starts_at' => '2026-01-01',
        'next_due_at' => '2026-08-29',
    ]);

    $service = app(FinanceRecurringExpenseService::class);
    $service->generateDue(CarbonImmutable::parse('2026-08-29'));
    $service->generateDue(CarbonImmutable::parse('2026-08-29'));
    $service->generateDue(CarbonImmutable::parse('2026-08-29'));

    expect(FinanceExpense::where('recurring_expense_id', $recurring->id)->count())->toBe(1);
});

it('catches up multiple missed periods in one run without creating duplicates', function (): void {
    [, , $organization, $category] = freWorkspace();

    $recurring = app(FinanceRecurringExpenseService::class)->create($organization, [
        'title' => 'Weekly cleaning service',
        'category_id' => $category->id,
        'amount' => 20,
        'frequency' => 'weekly',
        'starts_at' => '2026-06-01',
        'next_due_at' => '2026-06-01',
    ]);

    // Nothing ran for ~3 weeks — the command should catch up all of them at once.
    $summary = app(FinanceRecurringExpenseService::class)->generateDue(CarbonImmutable::parse('2026-06-22'));

    expect($summary['generated'])->toBe(4); // 06-01, 06-08, 06-15, 06-22
    expect(FinanceExpense::where('recurring_expense_id', $recurring->id)->count())->toBe(4);

    $recurring->refresh();
    expect($recurring->next_due_at->toDateString())->toBe('2026-06-29');
});

it('does not generate expenses for a paused or cancelled recurring expense', function (): void {
    [, , $organization, $category] = freWorkspace();
    $service = app(FinanceRecurringExpenseService::class);

    $recurring = $service->create($organization, [
        'title' => 'Paused thing',
        'category_id' => $category->id,
        'amount' => 20,
        'frequency' => 'monthly',
        'starts_at' => '2026-01-01',
        'next_due_at' => '2026-08-29',
    ]);
    $service->pause($recurring);

    $summary = $service->generateDue(CarbonImmutable::parse('2026-08-29'));

    expect($summary['processed'])->toBe(0);
    expect(FinanceExpense::where('recurring_expense_id', $recurring->id)->exists())->toBeFalse();
});

it('never leaks another organization\'s recurring expenses', function (): void {
    [$ownerA, , $orgA, $categoryA] = freWorkspace('FRE Org A');
    [, , $orgB, $categoryB] = freWorkspace('FRE Org B');

    $service = app(FinanceRecurringExpenseService::class);
    $service->create($orgA, ['title' => 'Org A Sub', 'category_id' => $categoryA->id, 'amount' => 10, 'frequency' => 'monthly', 'starts_at' => '2026-01-01', 'next_due_at' => '2026-09-01']);
    $recurringB = $service->create($orgB, ['title' => 'Org B Sub', 'category_id' => $categoryB->id, 'amount' => 10, 'frequency' => 'monthly', 'starts_at' => '2026-01-01', 'next_due_at' => '2026-09-01']);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/recurring')->assertOk();
    $titles = collect($response->viewData('page')['props']['recurring'])->pluck('title');

    expect($titles)->toContain('Org A Sub')->and($titles)->not->toContain('Org B Sub');

    $this->actingAs($ownerA)->get("/dashboard/finance/recurring/{$recurringB->id}/edit")->assertStatus(404);
});
