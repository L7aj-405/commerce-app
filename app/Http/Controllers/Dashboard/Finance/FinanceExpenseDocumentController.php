<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceDocumentUploadRequest;
use App\Models\FinanceExpense;
use App\Services\Finance\FinanceDocumentService;
use Illuminate\Http\RedirectResponse;

class FinanceExpenseDocumentController extends Controller
{
    /**
     * Attaches one or more supporting documents to an expense. Allowed for a
     * PAID expense too (see FinanceDocumentUploadRequest::authorize) — this
     * never touches the expense's own fields or the ledger, so it can never
     * violate the paid-expense field lock.
     */
    public function store(FinanceDocumentUploadRequest $request, FinanceExpense $expense, FinanceDocumentService $service): RedirectResponse
    {
        $service->storeMany(
            documentable: $expense,
            organization: $expense->organization,
            files: $request->file('documents'),
            uploadedBy: $request->user(),
            documentType: $request->validated('document_type'),
            description: $request->validated('description'),
            storeId: $expense->store_id,
        );

        return back()->with('success', 'Document(s) uploaded.');
    }
}
