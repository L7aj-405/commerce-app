<?php

declare(strict_types=1);

use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Internal justification / owner-review workflow for expenses paid without
 * an official invoice — see FinanceExpenseService's class docblock.
 *
 * @return array{0: User, 1: Store, 2: Organization, 3: FinanceExpenseCategory}
 */
function fejWorkspace(string $name = 'FEJ Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Field expenses', 'slug' => 'field-expenses']);

    return [$owner, $store, $organization, $category];
}

it('rejects a no-invoice expense missing beneficiary and reason', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Fuel for delivery van',
        'amount' => 150,
        'category_id' => $category->id,
        'expense_date' => now()->toDateString(),
        'justification_type' => 'no_invoice',
        // beneficiary_name, justification_reason, paid_by, payment_method deliberately omitted
    ])->assertSessionHasErrors(['beneficiary_name', 'justification_reason', 'paid_by', 'payment_method']);

    expect(FinanceExpense::where('title', 'Fuel for delivery van')->exists())->toBeFalse();
});

it('creates a no-invoice expense with the required justification fields and flags it pending owner review', function (): void {
    [$owner, $store, $organization, $category] = fejWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Fuel for delivery van',
        'amount' => 150,
        'category_id' => $category->id,
        'store_id' => $store->id,
        'expense_date' => now()->toDateString(),
        'justification_type' => 'no_invoice',
        'beneficiary_name' => 'Gas station attendant',
        'justification_reason' => 'Refueled the delivery van for today\'s rounds',
        'paid_by' => 'Rachid (driver)',
        'payment_method' => 'cash',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $expense = FinanceExpense::where('title', 'Fuel for delivery van')->firstOrFail();

    expect($expense->organization_id)->toBe($organization->id)
        ->and($expense->justification_type->value)->toBe('no_invoice')
        ->and($expense->justification_status->value)->toBe('needs_review')
        ->and($expense->owner_review_status->value)->toBe('pending')
        ->and($expense->beneficiary_name)->toBe('Gas station attendant')
        ->and($expense->paid_by)->toBe('Rachid (driver)');
});

it('creates an internal cash voucher expense flagged internal_only, not needs_review', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Porter tip',
        'amount' => 50,
        'category_id' => $category->id,
        'expense_date' => now()->toDateString(),
        'justification_type' => 'internal_cash_voucher',
        'beneficiary_name' => 'Warehouse porter',
        'justification_reason' => 'Helped unload a delivery truck',
        'paid_by' => 'Owner',
        'payment_method' => 'cash',
    ])->assertSessionHasNoErrors();

    $expense = FinanceExpense::where('title', 'Porter tip')->firstOrFail();

    expect($expense->justification_type->value)->toBe('internal_cash_voucher')
        ->and($expense->justification_status->value)->toBe('internal_only')
        ->and($expense->owner_review_status->value)->toBe('pending');
});

it('leaves an official-document expense with no owner-review flag at all — existing behaviour unchanged', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $this->actingAs($owner)->post('/dashboard/finance/expenses', [
        'title' => 'Cloud hosting invoice',
        'amount' => 199,
        'category_id' => $category->id,
        'expense_date' => now()->toDateString(),
        // justification_type omitted entirely — every pre-existing caller
    ])->assertSessionHasNoErrors();

    $expense = FinanceExpense::where('title', 'Cloud hosting invoice')->firstOrFail();

    expect($expense->justification_type->value)->toBe('official_document')
        ->and($expense->justification_status->value)->toBe('documented')
        ->and($expense->owner_review_status)->toBeNull();
});

it('still creates the expense_paid cash-out transaction for a paid no-invoice expense', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Field repair',
        'amount' => 80,
        'expense_date' => now()->toDateString(),
        'status' => 'unpaid',
        'payment_method' => 'cash',
        'justification_type' => 'no_invoice',
        'justification_status' => 'needs_review',
        'owner_review_status' => 'pending',
        'beneficiary_name' => 'Mechanic',
        'justification_reason' => 'Emergency van repair',
        'paid_by' => 'Owner',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash'])->assertRedirect();

    $expense->refresh();
    expect($expense->status->value)->toBe('paid');

    $tx = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->direction->value)->toBe('out')
        ->and((float) $tx->amount)->toBe(80.0);
});

it('lets the owner approve an internal expense', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Porter tip', 'amount' => 50, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'internal_cash_voucher', 'justification_status' => 'internal_only', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'Porter', 'justification_reason' => 'Unloading help', 'paid_by' => 'Owner',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/approve", ['note' => 'Looks legitimate'])->assertRedirect();

    $expense->refresh();
    expect($expense->owner_review_status->value)->toBe('approved')
        ->and($expense->owner_reviewed_by)->toBe($owner->id)
        ->and($expense->owner_reviewed_at)->not->toBeNull()
        ->and($expense->owner_review_note)->toBe('Looks legitimate');
});

it('lets the owner reject an internal expense', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Questionable expense', 'amount' => 50, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'Unknown', 'justification_reason' => 'Unclear', 'paid_by' => 'Someone',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/reject", ['note' => 'Not a real business expense'])->assertRedirect();

    $expense->refresh();
    expect($expense->owner_review_status->value)->toBe('rejected')
        ->and($expense->owner_review_note)->toBe('Not a real business expense')
        // Never paid to begin with — nothing to reverse, just the review flag.
        ->and($expense->status->value)->toBe('unpaid');
});

it('rejecting a PAID internal expense reverses the ledger transaction instead of deleting it', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Paid then rejected', 'amount' => 120, 'expense_date' => now()->toDateString(),
        'status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'Someone', 'justification_reason' => 'Claimed as urgent', 'paid_by' => 'Owner',
    ]);
    app(\App\Services\Finance\FinanceExpenseService::class)->recordPaidTransactionIfNeeded($expense);

    $paidTx = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/reject", ['note' => 'Should never have been paid'])->assertRedirect();

    $expense->refresh();
    expect($expense->owner_review_status->value)->toBe('rejected')
        ->and($expense->status->value)->toBe('cancelled');

    // The original expense_paid row is untouched — never deleted.
    expect(FinanceTransaction::find($paidTx->id))->not->toBeNull();

    $reversal = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->first();
    expect($reversal)->not->toBeNull()
        ->and($reversal->direction->value)->toBe('in')
        ->and((float) $reversal->amount)->toBe(120.0);
});

it('rejects approving/rejecting an official-document expense — no review workflow applies to it', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Documented expense', 'amount' => 50, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'official_document', 'justification_status' => 'documented',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/approve")->assertSessionHasErrors();
});

it('separates official and internal-only expenses in the monthly statement', function (): void {
    [$owner, $store, $organization, $category] = fejWorkspace();
    $month = now()->format('Y-m');

    FinanceExpense::create([
        'organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id,
        'title' => 'Documented', 'amount' => 300, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'official_document', 'justification_status' => 'documented',
    ]);
    FinanceExpense::create([
        'organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id,
        'title' => 'Voucher', 'amount' => 40, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'internal_cash_voucher', 'justification_status' => 'internal_only', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);
    FinanceExpense::create([
        'organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id,
        'title' => 'Undocumented', 'amount' => 60, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);

    $statement = app(\App\Services\Finance\FinanceMonthlyStatementService::class)->forMonth($month, null, $organization);

    expect($statement['justification']['official_documented']['amount'])->toBe(300.0)
        ->and($statement['justification']['internal_cash_voucher']['amount'])->toBe(40.0)
        ->and($statement['justification']['missing_no_document']['amount'])->toBe(60.0)
        ->and($statement['justification']['fiscal_ready_amount'])->toBe(300.0)
        ->and($statement['justification']['internal_only_amount'])->toBe(100.0)
        ->and($statement['justification']['pending_owner_review']['count'])->toBe(2);
});

it('uploading an internal_voucher document to a no_invoice expense does NOT mark it official documented', function (): void {
    [$owner, , , $category] = fejWorkspace();
    Storage::fake('local');

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Field cash payout', 'amount' => 70, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('signed-voucher.pdf', 100, 'application/pdf')],
        'document_type' => 'internal_voucher',
    ])->assertRedirect();

    $expense->refresh();
    expect($expense->justification_status->value)->toBe('internal_only')
        ->and($expense->justification_status->value)->not->toBe('documented')
        ->and($expense->fiscal_ready)->toBeFalse();
});

it('uploading an internal_voucher document marks the expense internally documented (internal_only)', function (): void {
    [$owner, , , $category] = fejWorkspace();
    Storage::fake('local');

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Porter tip', 'amount' => 50, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'internal_cash_voucher', 'justification_status' => 'internal_only', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('signed-voucher.pdf', 100, 'application/pdf')],
        'document_type' => 'internal_voucher',
    ])->assertRedirect();

    $document = \App\Models\FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();
    expect($document->is_official_document)->toBeFalse()
        ->and($document->is_internal_document)->toBeTrue();

    $expense->refresh();
    expect($expense->justification_status->value)->toBe('internal_only')
        ->and($expense->fiscal_ready)->toBeFalse();
});

it('uploading an official invoice marks the expense documented and fiscal_ready', function (): void {
    [$owner, , , $category] = fejWorkspace();
    Storage::fake('local');

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Undocumented at first', 'amount' => 90, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
        'document_type' => 'invoice',
    ])->assertRedirect();

    $document = \App\Models\FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();
    expect($document->is_official_document)->toBeTrue()
        ->and($document->is_internal_document)->toBeFalse();

    $expense->refresh();
    expect($expense->justification_status->value)->toBe('documented')
        ->and($expense->fiscal_ready)->toBeTrue();
});

it('an internal voucher expense still creates a cash-out transaction once paid', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Driver fuel top-up', 'amount' => 60, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'payment_method' => 'cash',
        'justification_type' => 'internal_cash_voucher', 'justification_status' => 'internal_only', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'Driver', 'justification_reason' => 'Fuel', 'paid_by' => 'Owner',
    ]);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash'])->assertRedirect();

    $tx = FinanceTransaction::where('source_type', FinanceExpense::class)->where('source_id', $expense->id)->where('type', 'expense_paid')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->direction->value)->toBe('out')
        ->and((float) $tx->amount)->toBe(60.0);
});

it('excludes an internal voucher expense from the fiscal-ready total even after it is paid', function (): void {
    [$owner, $store, $organization, $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $organization->id, 'store_id' => $store->id, 'category_id' => $category->id,
        'title' => 'Voucher expense', 'amount' => 65, 'expense_date' => now()->toDateString(), 'status' => 'unpaid', 'payment_method' => 'cash',
        'justification_type' => 'internal_cash_voucher', 'justification_status' => 'internal_only', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash']);

    $statement = app(\App\Services\Finance\FinanceMonthlyStatementService::class)->forMonth(now()->format('Y-m'), null, $organization);

    expect($statement['justification']['fiscal_ready_amount'])->toBe(0.0)
        ->and($statement['justification']['internal_only_amount'])->toBe(65.0)
        // But it DID move real cash — the cashflow total still sees it.
        ->and($statement['justification']['cashflow_total'])->toBeGreaterThanOrEqual(65.0);
});

it('renders the internal voucher print page for an authorized user', function (): void {
    [$owner, , , $category] = fejWorkspace();

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Printable voucher expense', 'amount' => 45, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'Someone', 'justification_reason' => 'Reason', 'paid_by' => 'Owner',
    ]);

    $response = $this->actingAs($owner)->get("/dashboard/finance/expenses/{$expense->id}/voucher");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('never lets a user print another organization\'s internal voucher', function (): void {
    [$ownerA] = fejWorkspace('FEJ Voucher A');
    [, , $orgB, $categoryB] = fejWorkspace('FEJ Voucher B');

    $expenseB = FinanceExpense::create([
        'organization_id' => $orgB->id, 'category_id' => $categoryB->id,
        'title' => 'Org B voucher expense', 'amount' => 45, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'Someone', 'justification_reason' => 'Reason', 'paid_by' => 'Owner',
    ]);

    $this->actingAs($ownerA)->get("/dashboard/finance/expenses/{$expenseB->id}/voucher")->assertStatus(404);
});

it('updates justification_status to documented when an official document is added later', function (): void {
    [$owner, , , $category] = fejWorkspace();
    Storage::fake('local');

    $expense = FinanceExpense::create([
        'organization_id' => $category->organization_id,
        'category_id' => $category->id,
        'title' => 'Initially undocumented', 'amount' => 90, 'expense_date' => now()->toDateString(), 'status' => 'unpaid',
        'justification_type' => 'no_invoice', 'justification_status' => 'needs_review', 'owner_review_status' => 'pending',
        'beneficiary_name' => 'X', 'justification_reason' => 'Y', 'paid_by' => 'Z',
    ]);

    expect($expense->justification_status->value)->toBe('needs_review');

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
        'document_type' => 'invoice',
    ])->assertRedirect();

    $expense->refresh();
    expect($expense->justification_status->value)->toBe('documented')
        // The originally-declared type is untouched — only the live status moved.
        ->and($expense->justification_type->value)->toBe('no_invoice');
});
