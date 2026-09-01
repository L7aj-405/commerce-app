<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\FinanceDocumentType;
use App\Enums\FinanceExpenseJustificationType;
use App\Enums\FinancePaymentMethod;
use App\Models\FinanceExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        return $expense
            ? $this->user()->can('update', $expense)
            : $this->user()->can('create', FinanceExpense::class);
    }

    /**
     * A request that never mentions justification_type at all (every
     * pre-existing caller — old tests, any future direct API use) must
     * behave exactly like an explicit official_document choice: normalize
     * it here BEFORE validation runs, so the required_unless rules below
     * only ever fire for a request that deliberately picked a non-official
     * path, never for one that's simply unaware of this field.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('justification_type')) {
            $this->merge(['justification_type' => FinanceExpenseJustificationType::OfficialDocument->value]);
        }
    }

    public function rules(): array
    {
        $organizationId = $this->user()->getActiveStore()?->organization_id;
        $storeIds = $this->user()->getActiveStore()?->organization?->stores()->pluck('id') ?? collect();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'category_id' => [
                'required', 'string',
                Rule::exists('finance_expense_categories', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'vendor_id' => [
                'nullable', 'string',
                Rule::exists('finance_vendors', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'store_id' => [
                'nullable', 'string',
                Rule::in($storeIds->all()),
            ],
            'expense_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:expense_date'],
            // required_unless (not just 'nullable') so a plain PATCH that
            // never sends payment_method at all still fails loudly for a
            // no-invoice/internal-voucher expense — see rules() note below.
            'payment_method' => ['nullable', 'required_unless:justification_type,official_document', Rule::enum(FinancePaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],

            // Justification — see FinanceExpenseService's class docblock.
            // Missing entirely defaults to official_document (unchanged
            // Phase-1 behaviour for every existing caller); the moment
            // anything else is chosen, beneficiary/reason/payer become
            // required. Never touches paid/unpaid/cancel — only reporting
            // and the owner-review workflow read these.
            'justification_type' => ['nullable', Rule::enum(FinanceExpenseJustificationType::class)],
            'beneficiary_name' => ['required_unless:justification_type,official_document', 'nullable', 'string', 'max:255'],
            'justification_reason' => ['required_unless:justification_type,official_document', 'nullable', 'string', 'max:2000'],
            'paid_by' => ['required_unless:justification_type,official_document', 'nullable', 'string', 'max:255'],
            'justification_notes' => ['nullable', 'string', 'max:2000'],

            // Optional supporting documents attached at creation time (the
            // Create page's "Documents justificatifs" section). Same
            // whitelist as the dedicated upload endpoint — see
            // FinanceDocumentUploadRequest. Purely evidentiary: never read
            // by FinanceExpenseService::create()/update() itself.
            'documents' => ['sometimes', 'array', 'max:20'],
            'documents.*' => [
                'file',
                'max:'.config('finance.documents.max_size_kb', 10240),
                'mimes:'.implode(',', config('finance.documents.allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png', 'webp'])),
                'mimetypes:'.implode(',', config('finance.documents.allowed_mime_types', ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])),
            ],
            'document_type' => ['nullable', Rule::enum(FinanceDocumentType::class)],
            'document_description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
