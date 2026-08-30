<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceExpenseStatus;
use App\Enums\FinanceRecurringFrequency;
use App\Enums\FinanceRecurringStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceRecurringExpense extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'category_id',
        'vendor_id',
        'title',
        'description',
        'amount',
        'currency',
        'frequency',
        'starts_at',
        'next_due_at',
        'reminder_days_before',
        'auto_create_expense',
        'generated_expense_status',
        'status',
        'last_generated_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        // Explicit `:Y-m-d` format — without it Eloquent serializes a `date`
        // cast to full ISO8601 with a time/zone suffix ("2026-08-29T00:00…Z")
        // in Inertia props/JSON, even though the column is date-only.
        'starts_at' => 'date:Y-m-d',
        'next_due_at' => 'date:Y-m-d',
        'reminder_days_before' => 'integer',
        'auto_create_expense' => 'boolean',
        'last_generated_at' => 'datetime',
        'frequency' => FinanceRecurringFrequency::class,
        'generated_expense_status' => FinanceExpenseStatus::class,
        'status' => FinanceRecurringStatus::class,
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

    public function generatedExpenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'recurring_expense_id');
    }

    public function isActive(): bool
    {
        return $this->status === FinanceRecurringStatus::Active;
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->next_due_at->isPast();
    }

    public function isUpcoming(int $withinDays = 30): bool
    {
        return $this->isActive()
            && ! $this->next_due_at->isPast()
            && $this->next_due_at->lte(now()->addDays($withinDays));
    }
}
