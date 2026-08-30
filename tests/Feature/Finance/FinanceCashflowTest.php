<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceExpenseService;
use App\Services\Finance\FinanceOrderTransactionService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use App\Services\Pos\OrderProcessingService;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: FinanceExpenseCategory}
 */
function cashflowWorkspace(string $name = 'Cashflow Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::create([
        'organization_id' => $organization->id, 'user_id' => $owner->id, 'name' => $name,
        'type' => 'hybrid', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $store->ensureDefaultRoles();

    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Software / Apps', 'slug' => 'software-apps']);

    return [$owner, $store, $organization, $category];
}

function cashflowProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Cashflow Product', 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]));
}

function cashflowPosSession(Store $store, User $cashier): PosSession
{
    return PosSession::create(['store_id' => $store->id, 'cashier_id' => $cashier->id, 'status' => 'open', 'opening_balance' => 0, 'opened_at' => now()]);
}

// --- Expenses -----------------------------------------------------------------

it('creates one expense_paid cash-out transaction when an expense is paid', function (): void {
    [$owner, $store, $organization, $category] = cashflowWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Hosting', 'amount' => 199, 'category_id' => $category->id,
        'store_id' => $store->id, 'expense_date' => now()->toDateString(), 'payment_method' => 'cash',
    ]);
    $expense = FinanceExpense::where('title', 'Hosting')->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    $transactions = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->get();
    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->type->value)->toBe('expense_paid')
        ->and($transactions->first()->direction->value)->toBe('out')
        ->and((float) $transactions->first()->amount)->toBe(199.0);
});

it('does not create a cash-out transaction for an unpaid expense', function (): void {
    [$owner, $store, , $category] = cashflowWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Unpaid bill', 'amount' => 50, 'category_id' => $category->id,
        'store_id' => $store->id, 'expense_date' => now()->toDateString(),
    ]);
    $expense = FinanceExpense::where('title', 'Unpaid bill')->firstOrFail();

    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->exists())->toBeFalse();
});

it('does not duplicate the expense_paid transaction when mark-paid is repeated', function (): void {
    [$owner, , , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Repeat', 'amount' => 75, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");

    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->count())->toBe(1);
});

it('reverses the cash-out and cancels (never hard-deletes) a paid expense, whether manual or recurring-generated', function (): void {
    [$owner, , , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Cancel me', 'amount' => 80, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->delete("/dashboard/finance/expenses/{$expense->id}")->assertRedirect();

    // A PAID expense is never hard-deleted, even when manually entered — it
    // is cancelled instead, so the row (and its ledger history) stays
    // auditable, and a reversal is recorded.
    expect($expense->refresh()->status->value)->toBe('cancelled');
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->exists())->toBeTrue();
    $reversal = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->first();
    expect($reversal)->not->toBeNull()->and((float) $reversal->amount)->toBe(80.0);

    // Net cash impact of "paid then cancelled" is zero.
    $net = (float) FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)
        ->get()->sum(fn (FinanceTransaction $t) => $t->direction->value === 'out' ? -$t->amount : ((float) $t->amount));
    expect($net)->toBe(0.0);

    // Same reversal path for a recurring-generated expense.
    $recurring = \App\Models\FinanceRecurringExpense::create([
        'organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Sub', 'amount' => 30,
        'frequency' => 'monthly', 'starts_at' => now()->toDateString(), 'next_due_at' => now()->toDateString(), 'status' => 'active',
    ]);
    $generatedExpense = FinanceExpense::create([
        'organization_id' => $category->organization_id, 'category_id' => $category->id, 'recurring_expense_id' => $recurring->id,
        'title' => 'Sub', 'amount' => 30, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
    ]);
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$generatedExpense->id}/mark-paid");
    $this->actingAs($owner)->delete("/dashboard/finance/expenses/{$generatedExpense->id}");

    expect($generatedExpense->refresh()->status->value)->toBe('cancelled');
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $generatedExpense->id)->where('type', 'expense_payment_reversed')->exists())->toBeTrue();
});

it('hard-deletes an unpaid manual expense with no cash impact', function (): void {
    [$owner, , , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Never paid', 'amount' => 45, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->delete("/dashboard/finance/expenses/{$expense->id}")->assertRedirect();

    expect(FinanceExpense::find($expense->id))->toBeNull();
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->exists())->toBeFalse();
});

it('labels a paid-expense reversal distinctly from a generic manual adjustment on the transactions ledger', function (): void {
    [$owner, , , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Office rent', 'amount' => 500, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid")->assertRedirect();

    $reversal = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->firstOrFail();

    // Its own type (not the generic 'manual_adjustment' used by the
    // Transactions page's free-form "Add adjustment" action) so the ledger
    // never lumps a reversal in with an unrelated manual entry.
    expect($reversal->type->value)->toBe('expense_payment_reversed')
        ->and($reversal->type->label())->toBe('Expense payment reversed')
        ->and($reversal->direction->value)->toBe('in');

    $response = $this->actingAs($owner)->get('/dashboard/finance/transactions')->assertOk();
    $options = $response->viewData('page')['props']['options']['types'];
    $reversedLabel = collect($options)->firstWhere('value', 'expense_payment_reversed')['label'];
    $manualLabel = collect($options)->firstWhere('value', 'manual_adjustment')['label'];

    expect($reversedLabel)->toBe('Expense payment reversed')->and($manualLabel)->toBe('Manual adjustment')->and($reversedLabel)->not->toBe($manualLabel);
});

it('does not create any ledger transaction when a plain (unpaid) expense is edited', function (): void {
    [$owner, $store, , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'store_id' => $store->id, 'category_id' => $category->id, 'title' => 'Draft bill', 'amount' => 60, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => 'Draft bill (revised)', 'amount' => 65, 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
    ])->assertRedirect();

    expect($expense->refresh()->title)->toBe('Draft bill (revised)')
        ->and((float) $expense->amount)->toBe(65.0)
        ->and($expense->status->value)->toBe('unpaid')
        ->and(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->exists())->toBeFalse();
});

// --- POS orders -----------------------------------------------------------------

it('creates sale_created + payment_collected for a cash POS order', function (): void {
    [$owner, $store] = cashflowWorkspace('POS Cash Store');
    $product = cashflowProduct($store, 'POS-CASH-1');
    $session = cashflowPosSession($store, $owner);

    $order = app(OrderProcessingService::class)->createOrder($store, $owner, [
        'pos_session_id' => $session->id, 'payment_method' => 'cash',
        'total_amount' => 100, 'amount_paid' => 100,
        'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'line_total' => 100]],
    ]);

    $sale = FinanceTransaction::where('source_type', PosOrder::class)->where('source_id', $order->id)->where('type', 'sale_created')->first();
    $collected = FinanceTransaction::where('source_type', PosOrder::class)->where('source_id', $order->id)->where('type', 'payment_collected')->first();

    expect($sale)->not->toBeNull()->and((float) $sale->amount)->toBe(100.0)
        ->and($collected)->not->toBeNull()
        ->and($collected->direction->value)->toBe('in')
        ->and($collected->account?->type->value)->toBe('cash');
});

it('creates a payment_collected transaction against the card account for a card POS order', function (): void {
    [$owner, $store] = cashflowWorkspace('POS Card Store');
    $product = cashflowProduct($store, 'POS-CARD-1');
    $session = cashflowPosSession($store, $owner);

    $order = app(OrderProcessingService::class)->createOrder($store, $owner, [
        'pos_session_id' => $session->id, 'payment_method' => 'card',
        'total_amount' => 250, 'amount_paid' => 250,
        'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 250, 'quantity' => 1, 'subtotal' => 250, 'line_total' => 250]],
    ]);

    $collected = FinanceTransaction::where('source_type', PosOrder::class)->where('source_id', $order->id)->where('type', 'payment_collected')->firstOrFail();

    expect($collected->account?->type->value)->toBe('card')
        ->and((float) $collected->amount)->toBe(250.0);
});

it('does not duplicate ledger entries when the same POS order is processed twice', function (): void {
    [$owner, $store] = cashflowWorkspace('POS Repeat Store');
    $product = cashflowProduct($store, 'POS-REPEAT-1');
    $session = cashflowPosSession($store, $owner);

    $order = app(OrderProcessingService::class)->createOrder($store, $owner, [
        'pos_session_id' => $session->id, 'payment_method' => 'cash',
        'total_amount' => 60, 'amount_paid' => 60,
        'items' => [['product_id' => $product->id, 'product_name' => $product->name, 'unit_price' => 60, 'quantity' => 1, 'subtotal' => 60, 'line_total' => 60]],
    ]);

    // Simulate the order being processed a second time (e.g. a retried job).
    app(FinanceOrderTransactionService::class)->syncPosOrderFinancials($order->fresh());
    app(FinanceOrderTransactionService::class)->syncPosOrderFinancials($order->fresh());

    expect(FinanceTransaction::where('source_type', PosOrder::class)->where('source_id', $order->id)->where('type', 'sale_created')->count())->toBe(1);
    expect(FinanceTransaction::where('source_type', PosOrder::class)->where('source_id', $order->id)->where('type', 'payment_collected')->count())->toBe(1);
});

// --- Online orders -----------------------------------------------------------------

it('records a COD order as a receivable, never as collected cash', function (): void {
    [, $store, $organization] = cashflowWorkspace('Online COD Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'platform_data' => []]);

    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'sale_created')->exists())->toBeTrue();
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_receivable_created')->exists())->toBeTrue();
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'payment_collected')->exists())->toBeFalse();
});

it('records a prepaid order (Shopify financial_status=paid) as collected cash, never as a COD receivable', function (): void {
    [, $store, $organization] = cashflowWorkspace('Online Prepaid Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'platform_data' => ['financial_status' => 'paid']]);

    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'payment_collected')->exists())->toBeTrue();
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_receivable_created')->exists())->toBeFalse();
});

it('does not duplicate transactions when the same order is synced twice', function (): void {
    [, $store, $organization] = cashflowWorkspace('Online Repeat Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id]);

    $service = app(FinanceOrderTransactionService::class);
    $service->syncOrderFinancials($order);
    $service->syncOrderFinancials($order);
    $service->syncOrderFinancials($order);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->count())->toBe(2); // sale_created + cod_receivable_created, exactly once each
});

it('does not count a cancelled order (never collected) as reversed cash', function (): void {
    [$owner, $store, $organization] = cashflowWorkspace('Online Cancel Uncollected Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Pending]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Cancelled, $owner, 'Customer changed their mind');

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'refund_paid')->exists())->toBeFalse();
});

it('reverses collected cash when a prepaid order is cancelled after payment', function (): void {
    [$owner, $store, $organization] = cashflowWorkspace('Online Cancel Collected Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'platform_data' => ['financial_status' => 'paid'], 'fulfillment_status' => FulfillmentStatus::Pending]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Cancelled, $owner, 'Refunded');

    $reversal = FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'refund_paid')->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->direction->value)->toBe('out')
        ->and((float) $reversal->amount)->toBe((float) $order->total);
});

it('creates a return_adjustment when a collected order is returned and the return is closed', function (): void {
    [$owner, $store, $organization] = cashflowWorkspace('Online Return Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'platform_data' => ['financial_status' => 'paid'], 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Returned, $owner, 'Customer returned item');
    $return = \App\Models\OrderReturn::where('returnable_type', Order::class)->where('returnable_id', $order->id)->firstOrFail();

    $returns = app(\App\Services\Orders\ReturnInspectionService::class);
    $lines = $return->items->map(fn ($item) => ['item_id' => $item->id, 'condition' => 'resellable'])->all();
    $returns->disposition($return, $lines, $owner);
    $returns->close($return, $owner);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'return_adjustment')->exists())->toBeTrue();
});

it('creates exactly one expense_payment_reversed transaction and is idempotent across repeated paid/unpaid cycles', function (): void {
    [$owner, , , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Toggle me', 'amount' => 220, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid")->assertRedirect();
    expect($expense->refresh()->status->value)->toBe('unpaid')->and($expense->paid_at)->toBeNull();

    // Repeating mark-unpaid on an already-unpaid expense must not duplicate anything.
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");

    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->count())->toBe(1);
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->count())->toBe(1);

    // Re-paying starts a brand new payment cycle — a SECOND expense_paid is
    // expected (the first one is already fully reversed, so it must not
    // block a new payment) — and re-unpaying reverses THAT one, giving a
    // second reversal. Repeating either action again afterwards must still
    // not duplicate anything within its own cycle.
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");

    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->count())->toBe(2);
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->count())->toBe(2);
});

it('blocks editing a paid expense\'s amount, payment method or date, keeping the expense and its ledger row consistent', function (): void {
    [$owner, $store, , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'store_id' => $store->id, 'category_id' => $category->id, 'title' => 'Locked once paid', 'amount' => 300, 'expense_date' => '2026-08-10', 'status' => 'unpaid']);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    // Attempting to change the amount is rejected.
    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => $expense->title, 'category_id' => $category->id, 'amount' => 999, 'expense_date' => '2026-08-10',
    ])->assertSessionHasErrors('amount');

    // Attempting to change the expense_date is rejected too.
    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => $expense->title, 'category_id' => $category->id, 'amount' => 300, 'expense_date' => '2026-08-15',
    ])->assertSessionHasErrors('amount');

    // A cosmetic-only edit (title) is still allowed.
    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => 'Renamed but still 300', 'category_id' => $category->id, 'amount' => 300, 'expense_date' => '2026-08-10',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $expense->refresh();
    $ledgerRow = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->firstOrFail();

    expect($expense->title)->toBe('Renamed but still 300')
        ->and((float) $expense->amount)->toBe(300.0)
        ->and((float) $ledgerRow->amount)->toBe(300.0);
});

it('reactivation bug: after a full pay-then-reverse cycle, editing the amount and marking paid again creates a NEW expense_paid with the new amount', function (): void {
    [$owner, , , $category] = cashflowWorkspace();
    $expense = FinanceExpense::create(['organization_id' => $category->organization_id, 'category_id' => $category->id, 'title' => 'Reactivate me', 'amount' => 100, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    // 1-2: create unpaid -> no transaction.
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->exists())->toBeFalse();

    // 3-4: mark paid -> expense_paid -100.
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);
    $firstPaid = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->firstOrFail();
    expect((float) $firstPaid->amount)->toBe(100.0)->and($firstPaid->direction->value)->toBe('out');

    // 5-6: mark unpaid -> expense_payment_reversed +100, linked to the payment it reversed.
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-unpaid");
    $reversal = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->firstOrFail();
    expect((float) $reversal->amount)->toBe(100.0)
        ->and($reversal->direction->value)->toBe('in')
        ->and($reversal->metadata['reverses_transaction_id'])->toBe($firstPaid->id)
        ->and($reversal->metadata['reversal_reason'])->toBe('Expense marked back to unpaid.');

    // 7: edit unpaid expense amount 100 -> 150 (allowed: status is unpaid).
    $this->actingAs($owner)->patch("/dashboard/finance/expenses/{$expense->id}", [
        'title' => $expense->title, 'category_id' => $category->id, 'amount' => 150, 'expense_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    // 8-9-10: mark paid again -> a NEW expense_paid -150 must be created (the
    // reactivation bug: the old, already-reversed expense_paid must not
    // block this).
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    $paidRows = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->orderBy('sequence')->get();
    expect($paidRows)->toHaveCount(2);
    expect((float) $paidRows[0]->amount)->toBe(100.0);
    expect((float) $paidRows[1]->amount)->toBe(150.0);
    expect($paidRows[1]->id)->not->toBe($firstPaid->id);

    // Old rows are untouched — append-only, no mutation of the original -100.
    expect((float) $firstPaid->refresh()->amount)->toBe(100.0);
    expect((float) $reversal->refresh()->amount)->toBe(100.0);

    // Net cash impact of the whole sequence: -100 +100 -150 = -150.
    $net = (float) FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)
        ->get()->sum(fn (FinanceTransaction $t) => $t->direction->value === 'out' ? -(float) $t->amount : (float) $t->amount);
    expect($net)->toBe(-150.0);
});

it('tenant isolation: an expense payment cycle in one organization never affects another organization\'s ledger', function (): void {
    [$ownerA, , , $categoryA] = cashflowWorkspace('Cycle Tenant A');
    [, , , $categoryB] = cashflowWorkspace('Cycle Tenant B');

    $expenseA = FinanceExpense::create(['organization_id' => $categoryA->organization_id, 'category_id' => $categoryA->id, 'title' => 'A expense', 'amount' => 100, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);
    $expenseB = FinanceExpense::create(['organization_id' => $categoryB->organization_id, 'category_id' => $categoryB->id, 'title' => 'B expense', 'amount' => 100, 'expense_date' => now()->toDateString(), 'status' => 'unpaid']);

    $this->actingAs($ownerA)->post("/dashboard/finance/expenses/{$expenseA->id}/mark-paid");
    $this->actingAs($ownerA)->post("/dashboard/finance/expenses/{$expenseA->id}/mark-unpaid");
    $this->actingAs($ownerA)->post("/dashboard/finance/expenses/{$expenseA->id}/mark-paid");

    // Org B's expense was never touched — no transactions of any kind.
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expenseB->id)->exists())->toBeFalse();
    // Org A's expense went through 2 payment cycles worth of activity.
    expect(FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expenseA->id)->where('type', 'expense_paid')->count())->toBe(2);
    expect(FinanceTransaction::where('organization_id', $categoryB->organization_id)->where('source_type', FinanceExpense::class)->exists())->toBeFalse();
});
