<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceExpenseJustificationStatus;
use App\Enums\FinanceExpenseJustificationType;
use App\Enums\FinanceExpenseOwnerReviewStatus;
use App\Enums\FinanceExpenseStatus;
use App\Enums\FinancePaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceExpense extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'category_id',
        'vendor_id',
        'recurring_expense_id',
        'title',
        'description',
        'amount',
        'currency',
        'expense_date',
        'due_date',
        'paid_at',
        'status',
        'payment_method',
        'reference',
        'attachment_path',
        'source_type',
        'source_id',
        'created_by',

        // Internal justification / owner-review workflow — see
        // FinanceExpenseService's docblock for the concept.
        'justification_type',
        'justification_status',
        'owner_review_status',
        'beneficiary_name',
        'justification_reason',
        'paid_by',
        'justification_notes',
        'owner_reviewed_by',
        'owner_reviewed_at',
        'owner_review_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        // Explicit `:Y-m-d` format — without it Eloquent serializes a `date`
        // cast to full ISO8601 with a time/zone suffix ("2026-08-29T00:00…Z")
        // in Inertia props/JSON, even though the column is date-only.
        'expense_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'paid_at' => 'datetime',
        'status' => FinanceExpenseStatus::class,
        'payment_method' => FinancePaymentMethod::class,
        'justification_type' => FinanceExpenseJustificationType::class,
        'justification_status' => FinanceExpenseJustificationStatus::class,
        'owner_review_status' => FinanceExpenseOwnerReviewStatus::class,
        'owner_reviewed_at' => 'datetime',
    ];

    protected $appends = [
        'fiscal_ready',
    ];

    /**
     * Whether this expense's amount belongs in a fiscal/accountant-ready
     * export — true only once justification_status is Documented (an
     * official invoice/receipt/fuel ticket/payment proof/supplier invoice
     * exists). An internal cash voucher, however thorough, is internal
     * transparency, never external legal proof — see
     * FinanceExpenseService's class docblock.
     */
    public function getFiscalReadyAttribute(): bool
    {
        return $this->justification_status === FinanceExpenseJustificationStatus::Documented;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceExpenseCategory::class, 'category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinanceVendor::class, 'vendor_id');
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(FinanceRecurringExpense::class, 'recurring_expense_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ownerReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_reviewed_by');
    }

    /**
     * Supporting documents/justificatifs (invoices, receipts, proofs of
     * payment...). Purely evidentiary — see App\Models\FinanceDocument's
     * docblock. Never touched by expense payment/cancellation/reversal.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(FinanceDocument::class, 'documentable')->latest();
    }

    public function isPaid(): bool
    {
        return $this->status === FinanceExpenseStatus::Paid;
    }

    public function isCancelled(): bool
    {
        return $this->status === FinanceExpenseStatus::Cancelled;
    }

    public function isOverdue(): bool
    {
        return $this->status === FinanceExpenseStatus::Unpaid
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    /** Whether the owner-review workflow applies to this expense at all — never true for an official-document expense. */
    public function requiresOwnerReview(): bool
    {
        return $this->justification_type !== FinanceExpenseJustificationType::OfficialDocument;
    }
}
