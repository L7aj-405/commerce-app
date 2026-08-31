<?php

declare(strict_types=1);

use App\Models\FinanceDocument;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: FinanceExpenseCategory}
 */
function fdWorkspace(string $name = 'FD Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    $category = FinanceExpenseCategory::create(['organization_id' => $organization->id, 'name' => 'Software / Apps', 'slug' => 'software-apps']);

    return [$owner, $store, $organization, $category];
}

function fdExpense(Organization $organization, FinanceExpenseCategory $category, ?Store $store = null, string $status = 'unpaid'): FinanceExpense
{
    return FinanceExpense::create([
        'organization_id' => $organization->id,
        'store_id' => $store?->id,
        'category_id' => $category->id,
        'title' => 'Server hosting',
        'amount' => 199.99,
        'expense_date' => now()->toDateString(),
        'status' => $status,
        'paid_at' => $status === 'paid' ? now() : null,
    ]);
}

/** A staff member limited to a specific set of Finance permissions — mirrors FinanceAccessTest::financeAddStaffWithRole(). */
function fdStaffWithPermissions(Store $store, Organization $organization, array $permissions): User
{
    $role = StoreRole::create([
        'store_id' => $store->id,
        'name' => 'FD Role '.implode('-', $permissions),
        'permissions' => $permissions,
        'is_system' => false,
    ]);

    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);

    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $staff->id,
        'role' => 'manager',
        'store_role_id' => $role->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    return $staff;
}

it('lets an authorized user upload a PDF document to an expense', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 500, 'application/pdf')],
        'document_type' => 'invoice',
        'description' => 'Hosting invoice',
    ])->assertRedirect();

    $document = FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();
    expect($document->documentable_type)->toBe(FinanceExpense::class)
        ->and($document->organization_id)->toBe($organization->id)
        ->and($document->document_type->value)->toBe('invoice')
        ->and($document->original_name)->toBe('invoice.pdf')
        ->and($document->uploaded_by)->toBe($owner->id);

    Storage::disk('local')->assertExists($document->path);
});

it('lets an authorized user upload an image receipt to an expense', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')],
        'document_type' => 'receipt',
    ])->assertRedirect();

    $document = FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();
    expect($document->document_type->value)->toBe('receipt')
        ->and($document->mime_type)->toStartWith('image/');
});

it('allows uploading a supporting document to an already-paid expense', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store, 'paid');

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')],
        'document_type' => 'payment_proof',
    ])->assertRedirect();

    expect(FinanceDocument::where('documentable_id', $expense->id)->count())->toBe(1);
    expect($expense->fresh()->status->value)->toBe('paid'); // untouched
});

it('never creates a finance_transaction when uploading or removing a document', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store, 'paid');

    $txCountBefore = FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()->where('source_id', $expense->id)->count());

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')],
    ])->assertRedirect();

    $document = FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();

    $this->actingAs($owner)->delete("/dashboard/finance/documents/{$document->id}")->assertRedirect();

    $txCountAfter = FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()->where('source_id', $expense->id)->count());

    expect($txCountAfter)->toBe($txCountBefore);
});

it('rejects an unsupported file type', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')],
    ])->assertSessionHasErrors('documents.0');

    expect(FinanceDocument::where('documentable_id', $expense->id)->count())->toBe(0);
});

it('rejects an oversized file', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')],
    ])->assertSessionHasErrors('documents.0');

    expect(FinanceDocument::where('documentable_id', $expense->id)->count())->toBe(0);
});

it('never lets a user upload a document to another organization\'s expense', function (): void {
    Storage::fake('local');
    [$ownerA] = fdWorkspace('FD Org A');
    [, $storeB, $orgB, $categoryB] = fdWorkspace('FD Org B');
    $expenseB = fdExpense($orgB, $categoryB, $storeB);

    $this->actingAs($ownerA)->post("/dashboard/finance/expenses/{$expenseB->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertStatus(404);

    expect(FinanceDocument::where('documentable_id', $expenseB->id)->count())->toBe(0);
});

it('never lets a user download another organization\'s document', function (): void {
    Storage::fake('local');
    [$ownerA] = fdWorkspace('FD Dl Org A');
    [$ownerB, $storeB, $orgB, $categoryB] = fdWorkspace('FD Dl Org B');
    $expenseB = fdExpense($orgB, $categoryB, $storeB);

    $this->actingAs($ownerB)->post("/dashboard/finance/expenses/{$expenseB->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertRedirect();
    $document = FinanceDocument::where('documentable_id', $expenseB->id)->firstOrFail();

    $this->actingAs($ownerA)->get("/dashboard/finance/documents/{$document->id}/download")->assertStatus(404);
});

it('lets a finance.view-only staff member download/view a document but not delete it', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertRedirect();
    $document = FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();

    $viewer = fdStaffWithPermissions($store, $organization, ['finance.view']);

    $this->actingAs($viewer)->get("/dashboard/finance/documents/{$document->id}/download")->assertOk();
    $this->actingAs($viewer)->delete("/dashboard/finance/documents/{$document->id}")->assertForbidden();

    expect($document->fresh()->trashed())->toBeFalse();
});

it('denies a staff member with no finance permission from downloading a document', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertRedirect();
    $document = FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();

    $noAccess = fdStaffWithPermissions($store, $organization, ['products.view']);

    $this->actingAs($noAccess)->get("/dashboard/finance/documents/{$document->id}/download")->assertForbidden();
});

it('removes a deleted document from the active list but keeps the file for audit', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertRedirect();
    $document = FinanceDocument::where('documentable_id', $expense->id)->firstOrFail();

    $this->actingAs($owner)->delete("/dashboard/finance/documents/{$document->id}")->assertRedirect();

    expect(FinanceDocument::where('documentable_id', $expense->id)->count())->toBe(0) // active list
        ->and(FinanceDocument::withTrashed()->where('documentable_id', $expense->id)->count())->toBe(1)
        ->and($document->fresh()->deleted_by)->toBe($owner->id);

    Storage::disk('local')->assertExists($document->path); // physical file kept for traceability

    $this->actingAs($owner)->get("/dashboard/finance/documents/{$document->id}/download")->assertStatus(404);
});

it('keeps documents attached when a paid expense is cancelled (reversal recorded, documents untouched)', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store, 'unpaid');

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertRedirect();

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/mark-paid", ['payment_method' => 'cash'])->assertRedirect();
    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/cancel")->assertRedirect();

    expect($expense->fresh()->status->value)->toBe('cancelled')
        ->and(FinanceDocument::where('documentable_id', $expense->id)->count())->toBe(1);

    $reversed = FinanceTransaction::withoutOrganizationTenancy(
        fn () => FinanceTransaction::query()->where('source_id', $expense->id)->where('type', 'expense_payment_reversed')->exists()
    );
    expect($reversed)->toBeTrue();
});

it('includes a document count on the expense list and monthly statement', function (): void {
    Storage::fake('local');
    [$owner, $store, $organization, $category] = fdWorkspace();
    $expense = fdExpense($organization, $category, $store);

    $this->actingAs($owner)->post("/dashboard/finance/expenses/{$expense->id}/documents", [
        'documents' => [UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')],
    ])->assertRedirect();

    $indexResponse = $this->actingAs($owner)->get('/dashboard/finance/expenses')->assertOk();
    $rows = collect($indexResponse->viewData('page')['props']['expenses']['data']);
    expect($rows->firstWhere('id', $expense->id)['documents_count'])->toBe(1);

    $statementResponse = $this->actingAs($owner)->get('/dashboard/finance/statement?month='.now()->format('Y-m'))->assertOk();
    $exportRows = collect($statementResponse->viewData('page')['props']['statement']['export_rows']);
    expect($exportRows->firstWhere('id', $expense->id)['document_count'])->toBe(1);
});
