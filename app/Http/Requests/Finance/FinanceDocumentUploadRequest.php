<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinanceDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceDocumentUploadRequest extends FormRequest
{
    /**
     * Uploading is authorized exactly like editing the parent expense
     * (finance.manage_expenses, same-organization) — including a PAID
     * expense, which `update` already allows. This is deliberate: documents
     * are supporting evidence only, never a ledger-sensitive field, so
     * uploading one is never blocked by the paid-expense field lock.
     */
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense !== null && $this->user()->can('update', $expense);
    }

    public function rules(): array
    {
        return [
            'documents' => ['required', 'array', 'min:1', 'max:20'],
            'documents.*' => [
                'file',
                'max:'.config('finance.documents.max_size_kb', 10240),
                'mimes:'.implode(',', config('finance.documents.allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png', 'webp'])),
                'mimetypes:'.implode(',', config('finance.documents.allowed_mime_types', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])),
            ],
            'document_type' => ['nullable', Rule::enum(FinanceDocumentType::class)],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
