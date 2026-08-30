<?php

declare(strict_types=1);

use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceVendor;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceMonthlyStatementService;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function fmsWorkspace(string $name = 'FMS Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store, $organization];
}

it('computes correct monthly statement totals by status, category, payment method and vendor', function (): void {
    [$owner, $store, $organization] = fmsWorkspace();

    $marketing = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Ads / Marketing', 'slug' => 'ads-marketing']);
    $hosting = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Domain / Hosting', 'slug' => 'domain-hosting']);
    $vendor = FinanceVendor::create(['organization_id' => $organization->id, 'name' => 'Cloud Co']);

    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $marketing->id, 'vendor_id' => $vendor->id, 'title' => 'Facebook ads', 'amount' => 200, 'expense_date' => '2026-08-05', 'status' => 'paid', 'payment_method' => 'card']);
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $hosting->id, 'title' => 'Server', 'amount' => 100, 'expense_date' => '2026-08-10', 'status' => 'paid', 'payment_method' => 'bank_transfer']);
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $hosting->id, 'title' => 'Backup service', 'amount' => 50, 'expense_date' => '2026-08-15', 'status' => 'unpaid']);
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $marketing->id, 'title' => 'Cancelled campaign', 'amount' => 999, 'expense_date' => '2026-08-20', 'status' => 'cancelled']);
    // Outside the statement month — must not be counted.
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $marketing->id, 'title' => 'July expense', 'amount' => 1000, 'expense_date' => '2026-07-15', 'status' => 'paid']);

    $statement = app(FinanceMonthlyStatementService::class)->forMonth('2026-08');

    // total_expenses is the ACTIVE total (paid + unpaid) — cancelled is
    // excluded here and reported separately via cancelled_expenses, an
    // audit/history line, not an active total (Finance Expense↔Ledger rules).
    expect($statement['totals']['total_expenses'])->toEqual(['count' => 3, 'amount' => 350.0])
        ->and($statement['totals']['paid_expenses'])->toEqual(['count' => 2, 'amount' => 300.0])
        ->and($statement['totals']['unpaid_expenses'])->toEqual(['count' => 1, 'amount' => 50.0])
        ->and($statement['totals']['cancelled_expenses'])->toEqual(['count' => 1, 'amount' => 999.0]);

    $byCategory = collect($statement['by_category'])->keyBy('category_name');
    expect($byCategory['Ads / Marketing']['amount'])->toBe(200.0) // cancelled campaign excluded
        ->and($byCategory['Domain / Hosting']['amount'])->toBe(150.0);

    $byMethod = collect($statement['by_payment_method'])->keyBy('payment_method');
    expect($byMethod['card']['amount'])->toBe(200.0)
        ->and($byMethod['bank_transfer']['amount'])->toBe(100.0);

    $byVendor = collect($statement['by_vendor'])->keyBy('vendor_name');
    expect($byVendor['Cloud Co']['amount'])->toBe(200.0);

    expect($statement['export_rows'])->toHaveCount(4);
});

it('scopes the monthly statement to a single store when requested', function (): void {
    [, $storeA, $organization] = fmsWorkspace('FMS Multi A');
    $storeB = Store::factory()->create(['user_id' => $storeA->user_id, 'organization_id' => $organization->id, 'name' => 'Second Store']);
    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Other', 'slug' => 'other']);

    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $storeA->id, 'category_id' => $category->id, 'title' => 'Store A expense', 'amount' => 40, 'expense_date' => '2026-08-05', 'status' => 'paid']);
    FinanceExpense::create(['organization_id' => $organization->id, 'store_id' => $storeB->id, 'category_id' => $category->id, 'title' => 'Store B expense', 'amount' => 60, 'expense_date' => '2026-08-06', 'status' => 'paid']);

    $statement = app(FinanceMonthlyStatementService::class)->forMonth('2026-08', $storeA->id);

    expect($statement['totals']['total_expenses'])->toEqual(['count' => 1, 'amount' => 40.0]);
});
