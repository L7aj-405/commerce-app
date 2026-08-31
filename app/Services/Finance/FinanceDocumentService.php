<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinanceDocument;
use App\Models\FinanceExpense;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The single write path for finance_documents. Purely evidentiary storage —
 * this NEVER touches finance_transactions/the ledger, and never validates or
 * mutates the documentable's own financial fields. Files are written to a
 * private disk (never "public") under
 * finance/{organization_id}/{segment}/{documentable_id}/{uuid}.{ext} — the
 * client-supplied filename is kept only as `original_name` for display,
 * never used to build the stored path.
 */
class FinanceDocumentService
{
    /**
     * @param  UploadedFile[]  $files
     * @return Collection<int, FinanceDocument>
     */
    public function storeMany(
        Model $documentable,
        Organization $organization,
        array $files,
        User $uploadedBy,
        ?string $documentType = null,
        ?string $description = null,
        ?string $storeId = null,
    ): Collection {
        return collect($files)->map(
            fn (UploadedFile $file) => $this->storeOne($documentable, $organization, $file, $uploadedBy, $documentType, $description, $storeId)
        );
    }

    public function storeOne(
        Model $documentable,
        Organization $organization,
        UploadedFile $file,
        User $uploadedBy,
        ?string $documentType = null,
        ?string $description = null,
        ?string $storeId = null,
    ): FinanceDocument {
        $disk = config('finance.documents.disk', 'local');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedName = (string) Str::uuid().'.'.$extension;
        $directory = sprintf(
            'finance/%s/%s/%s',
            $organization->id,
            $this->pathSegmentFor($documentable),
            $documentable->getKey(),
        );
        $path = $directory.'/'.$storedName;

        Storage::disk($disk)->putFileAs($directory, $file, $storedName);

        return FinanceDocument::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $storeId,
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'extension' => $extension,
            'size_bytes' => $file->getSize() ?: 0,
            'document_type' => $documentType,
            'description' => $description,
            'uploaded_by' => $uploadedBy->id,
        ]);
    }

    /**
     * Soft-deletes the document row only — the physical file is deliberately
     * left in place. Finance documents are audit/traceability evidence: a
     * cancelled or previously-paid expense must keep its history reviewable,
     * so nothing here ever unlinks or destroys the underlying file.
     */
    public function delete(FinanceDocument $document, User $deletedBy): void
    {
        $document->update(['deleted_by' => $deletedBy->id]);
        $document->delete();
    }

    private function pathSegmentFor(Model $documentable): string
    {
        return match (true) {
            $documentable instanceof FinanceExpense => 'expenses',
            default => Str::snake(Str::pluralStudly(class_basename($documentable))),
        };
    }
}
