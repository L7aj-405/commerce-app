<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceExpenseStatus;
use App\Enums\FinancePaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

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
}
