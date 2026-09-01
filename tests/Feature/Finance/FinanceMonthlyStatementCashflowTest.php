<?php

declare(strict_types=1);

use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Enums\FulfillmentStatus;
use App\Models\FinanceAccount;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceMonthlyStatementService;
use App\Services\Finance\FinanceOrderTransactionService;
use App\Services\Finance\FinanceTransactionService;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: FinanceExpenseCategory}
 */
function statementCashflowWorkspace(string $name = 'Statement Cashflow Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Other', 'slug' => 'other']);

    return [$owner, $store, $organization, $category];
}

it('shows an August sale in August\'s statement and its September collection in September\'s statement', function (): void {
    [, $store, $organization] = statementCashflowWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id]);

    $ledger = app(FinanceTransactionService::class);
    $ledger->record([
        'organization_id' => $organization->id, 'store_id' => $store->id,
        'direction' => FinanceTransactionDirection::Neutral, 'type' => FinanceTransactionType::SaleCreated,
        'amount' => 500, 'occurred_at' => '2026-08-20', 'source_type' => Order::class, 'source_id' => $order->id,
    ]);
    $ledger->record([
        'organization_id' => $organization->id, 'store_id' => $store->id,
        'direction' => FinanceTransactionDirection::In, 'type' => FinanceTransactionType::PaymentCollected,
        'amount' => 500, 'occurred_at' => '2026-09-03', 'source_type' => Order::class, 'source_id' => $order->id,
    ]);

    $service = app(FinanceMonthlyStatementService::class);
    $august = $service->forMonth('2026-08');
    $september = $service->forMonth('2026-09');

    expect($august['cashflow']['sales_created'])->toEqual(['count' => 1, 'amount' => 500.0])
        ->and($august['cashflow']['collections'])->toEqual(['count' => 0, 'amount' => 0.0]);

    expect($september['cashflow']['sales_created'])->toEqual(['count' => 0, 'amount' => 0.0])
        ->and($september['cashflow']['collections'])->toEqual(['count' => 1, 'amount' => 500.0]);
});

it('counts expenses paid by the ledger\'s expense_paid transaction date, not expense_date', function (): void {
    [, , $organization, $category] = statementCashflowWorkspace();
    $ledger = app(FinanceTransactionService::class);

    // Recorded (expense_date) in July, but actually paid in August.
    $julyBill = FinanceExpense::create([
        'organization_id' => $organization->id, 'category_id' => $category->id, 'title' => 'Late-paid July bill',
        'amount' => 120, 'expense_date' => '2026-07-28', 'paid_at' => '2026-08-02 10:00:00', 'status' => 'paid',
    ]);
    $ledger->record([
        'organization_id' => $organization->id, 'direction' => FinanceTransactionDirection::Out, 'type' => FinanceTransactionType::ExpensePaid,
        'amount' => 120, 'occurred_at' => '2026-08-02 10:00:00', 'source_type' => FinanceExpense::class, 'source_id' => $julyBill->id,
    ]);
    // Recorded AND paid in August.
    $augustBill = FinanceExpense::create([
        'organization_id' => $organization->id, 'category_id' => $category->id, 'title' => 'August bill',
        'amount' => 80, 'expense_date' => '2026-08-05', 'paid_at' => '2026-08-05 10:00:00', 'status' => 'paid',
    ]);
    $ledger->record([
        'organization_id' => $organization->id, 'direction' => FinanceTransactionDirection::Out, 'type' => FinanceTransactionType::ExpensePaid,
        'amount' => 80, 'occurred_at' => '2026-08-05 10:00:00', 'source_type' => FinanceExpense::class, 'source_id' => $augustBill->id,
    ]);

    $august = app(FinanceMonthlyStatementService::class)->forMonth('2026-08');
    $july = app(FinanceMonthlyStatementService::class)->forMonth('2026-07');

    // cashflow.expenses_paid is ledger-based (finance_transactions only):
    // both bills' expense_paid rows landed in August.
    expect($august['cashflow']['expenses_paid'])->toEqual(['count' => 2, 'amount' => 200.0]);
    // totals.paid_expenses (Phase 1, expense_date-based) still only sees the August-dated one in August...
    expect($august['totals']['paid_expenses'])->toEqual(['count' => 1, 'amount' => 80.0]);
    // ...and the July-dated one shows up in July's expense_date-based total instead.
    expect($july['totals']['paid_expenses'])->toEqual(['count' => 1, 'amount' => 120.0])
        ->and($july['cashflow']['expenses_paid'])->toEqual(['count' => 0, 'amount' => 0.0]);
});

it('monthly statement cashflow keeps counting a paid expense\'s cash-out even after its finance_expenses row is cancelled', function (): void {
    [$owner, $store, $organization, $category] = statementCashflowWorkspace();

    // markPaid()/cancel() stamp paid_at/the reversal's occurred_at with the
    // REAL now() — frozen inside August so this test stays deterministic
    // regardless of which day it actually runs on (both events must land
    // inside the '2026-08' month being asserted below).
    \Illuminate\Support\Carbon::setTestNow('2026-08-15 10:00:00');

    try {
        $expense = app(\App\Services\Finance\FinanceExpenseService::class)->create($organization, $owner, [
            'title' => 'Then cancelled', 'category_id' => $category->id, 'amount' => 150, 'expense_date' => '2026-08-10',
        ]);
        app(\App\Services\Finance\FinanceExpenseService::class)->markPaid($expense, 'cash');

        $beforeCancel = app(FinanceMonthlyStatementService::class)->forMonth('2026-08');
        expect($beforeCancel['cashflow']['expenses_paid'])->toEqual(['count' => 1, 'amount' => 150.0]);

        // Cancel it (the new correct path for a paid expense — never a hard
        // delete). The expense_paid transaction is append-only and untouched;
        // a reversal is recorded in the SAME month.
        app(\App\Services\Finance\FinanceExpenseService::class)->cancel($expense->fresh());

        $afterCancel = app(FinanceMonthlyStatementService::class)->forMonth('2026-08');

        // The ledger-derived cashflow figure is unaffected by the expense's
        // status changing — it's still what the ledger says was paid out.
        expect($afterCancel['cashflow']['expenses_paid'])->toEqual(['count' => 1, 'amount' => 150.0]);
        // The reversal nets the movement back to zero for this expense, and it
        // no longer inflates the ACTIVE total_expenses figure.
        expect($afterCancel['cashflow']['net_cash_movement'])->toBe($beforeCancel['cashflow']['net_cash_movement'] + 150.0);
        expect($afterCancel['totals']['total_expenses']['amount'])->toBe(0.0);
        expect($afterCancel['totals']['cancelled_expenses'])->toEqual(['count' => 1, 'amount' => 150.0]);
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});

it('keeps the monthly statement tenant-isolated for both expenses and cashflow', function (): void {
    [$ownerA, $storeA, $orgA, $categoryA] = statementCashflowWorkspace('Statement Tenant A');
    [, $storeB, $orgB, $categoryB] = statementCashflowWorkspace('Statement Tenant B');

    FinanceExpense::create(['organization_id' => $orgA->id, 'category_id' => $categoryA->id, 'title' => 'A expense', 'amount' => 40, 'expense_date' => '2026-08-10', 'status' => 'paid', 'paid_at' => '2026-08-10']);
    FinanceExpense::create(['organization_id' => $orgB->id, 'category_id' => $categoryB->id, 'title' => 'B expense', 'amount' => 999, 'expense_date' => '2026-08-10', 'status' => 'paid', 'paid_at' => '2026-08-10']);

    $orderA = Order::factory()->create(['store_id' => $storeA->id, 'organization_id' => $orgA->id]);
    $orderB = Order::factory()->create(['store_id' => $storeB->id, 'organization_id' => $orgB->id]);
    $ledger = app(FinanceTransactionService::class);
    $ledger->record(['organization_id' => $orgA->id, 'direction' => 'neutral', 'type' => 'sale_created', 'amount' => 300, 'occurred_at' => '2026-08-15', 'source_type' => Order::class, 'source_id' => $orderA->id]);
    $ledger->record(['organization_id' => $orgB->id, 'direction' => 'neutral', 'type' => 'sale_created', 'amount' => 700, 'occurred_at' => '2026-08-15', 'source_type' => Order::class, 'source_id' => $orderB->id]);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/statement?month=2026-08')->assertOk();
    $statement = $response->viewData('page')['props']['statement'];

    expect($statement['totals']['paid_expenses'])->toEqual(['count' => 1, 'amount' => 40.0])
        ->and($statement['cashflow']['sales_created'])->toEqual(['count' => 1, 'amount' => 300.0]);
});

it('does not count a confirmed-but-not-delivered COD order as collected cash in the monthly statement', function (): void {
    [$owner, $store, $organization] = statementCashflowWorkspace('Statement Not Delivered');
    app(FinanceAccountService::class)->ensureSeeded($organization);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'fulfillment_status' => FulfillmentStatus::Confirmed, 'total' => 500,
    ]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    // Backend refuses to settle/deposit/collect it — no cash-moving
    // transaction can exist for this order while it's undelivered.
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ])->assertSessionHasErrors('order_ids');

    $statement = app(FinanceMonthlyStatementService::class)->forMonth(now()->format('Y-m'));

    // The sale and the receivable are real (Neutral) facts — they show up
    // as a sale and as a still-pending receivable...
    expect($statement['cashflow']['sales_created']['amount'])->toBe(500.0)
        ->and($statement['cod']['pending_at_month_end'])->toBe(500.0);
    // ...but NONE of it counts as money collected, because no cash-moving
    // transaction (finance_transactions direction in/out) was ever created.
    expect($statement['cashflow']['collections'])->toEqual(['count' => 0, 'amount' => 0.0])
        ->and($statement['cashflow']['net_cash_movement'])->toBe(0.0)
        ->and($statement['cod']['collected_via_external_settlement']['net_received'])->toBe(0.0)
        ->and($statement['cod']['collected_via_courier_deposit']['cash_received'])->toBe(0.0);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)
        ->whereIn('type', ['cod_collected', 'cod_settled_external', 'cod_cleared_by_courier', 'cod_settlement_received'])
        ->exists())->toBeFalse();
});

it('monthly statement nets a same-month pay/reverse/repay cycle correctly: -100 +100 -150 = -150', function (): void {
    [$owner, , , $category] = statementCashflowWorkspace('Statement Cycle Same Month');
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Cycle expense', 'amount' => 100, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");
    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => $expense->title, 'category_id' => $category->id, 'amount' => 150, 'expense_date' => now()->toDateString(),
    ]);
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    $statement = app(FinanceMonthlyStatementService::class)->forMonth(now()->format('Y-m'));

    // Both expense_paid rows (100 then 150) land in "expenses paid this month".
    expect($statement['cashflow']['expenses_paid'])->toEqual(['count' => 2, 'amount' => 250.0]);
    // Net: -100 (paid) +100 (reversed) -150 (repaid) = -150.
    expect($statement['cashflow']['net_cash_movement'])->toBe(-150.0);
});

it('splits a pay/reverse/repay cycle across months: reversal shows in the reversal month, the new payment in its own month', function (): void {
    [$owner, , , $category] = statementCashflowWorkspace('Statement Cycle Cross Month');
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Cross-month expense', 'amount' => 100, 'expense_date' => '2026-07-15', 'status' => 'unpaid']);

    $this->travelTo(\Carbon\Carbon::parse('2026-07-15 10:00:00'));
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    $this->travelTo(\Carbon\Carbon::parse('2026-08-10 10:00:00'));
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");

    $this->travelTo(\Carbon\Carbon::parse('2026-08-20 10:00:00'));
    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => $expense->title, 'category_id' => $category->id, 'amount' => 150, 'expense_date' => '2026-08-20',
    ]);
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    $this->travelBack();

    $july = app(FinanceMonthlyStatementService::class)->forMonth('2026-07');
    $august = app(FinanceMonthlyStatementService::class)->forMonth('2026-08');

    // July: only the original -100 payment.
    expect($july['cashflow']['expenses_paid'])->toEqual(['count' => 1, 'amount' => 100.0])
        ->and($july['cashflow']['net_cash_movement'])->toBe(-100.0);

    // August: the reversal (+100) and the new -150 payment, never the July payment again.
    expect($august['cashflow']['expenses_paid'])->toEqual(['count' => 1, 'amount' => 150.0])
        ->and($august['cashflow']['net_cash_movement'])->toBe(-50.0);
});
