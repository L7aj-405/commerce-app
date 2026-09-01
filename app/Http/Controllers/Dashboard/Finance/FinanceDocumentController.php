<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceDocument;
use App\Models\FinanceExpense;
use App\Services\Finance\FinanceDocumentService;
use App\Services\Finance\FinanceExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read/delete access to an existing FinanceDocument. Route-model binding on
 * {document} already tenant-scopes via FinanceDocument's BelongsToOrganization
 * global scope (and excludes soft-deleted rows) exactly like every other
 * Finance model — a cross-organization or already-deleted document 404s
 * before the policy is even reached. See FinanceDocumentPolicy for the
 * finance.view/finance.manage_expenses split.
 */
class FinanceDocumentController extends Controller
{
    public function download(Request $request, FinanceDocument $document): StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    /** Inline view for PDF/image documents — used by the "preview" action; other types fall back to download. */
    public function preview(Request $request, FinanceDocument $document): StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless($document->isPreviewable(), 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->response($document->path, $document->original_name);
    }

    /** Soft-deletes the document row only — see FinanceDocumentService::delete(). */
    public function destroy(Request $request, FinanceDocument $document, FinanceDocumentService $service, FinanceExpenseService $expenses): RedirectResponse
    {
        $this->authorize('delete', $document);

        $documentable = $document->documentable;

        $service->delete($document, $request->user());

        // Removing the last official document can drop an expense back out
        // of Documented — see FinanceExpenseService::syncJustificationStatus().
        if ($documentable instanceof FinanceExpense) {
            $expenses->syncJustificationStatus($documentable);
        }

        return back()->with('success', 'Document removed.');
    }
}
