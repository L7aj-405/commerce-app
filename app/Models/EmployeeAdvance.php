<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeAdvanceStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvance extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'amount',
        'currency',
        'advance_date',
        'status',
        'account_id',
        'reason',
        'approved_by',
        'approved_at',
        'paid_by',
        'paid_at',
        'deducted_in_payroll_item_id',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'advance_date' => 'date:Y-m-d',
        'status' => EmployeeAdvanceStatus::class,
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function deductedInPayrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'deducted_in_payroll_item_id');
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [EmployeeAdvanceStatus::Paid, EmployeeAdvanceStatus::Deducted], true);
    }

    /** Available to be deducted from a future payroll run — paid out, not yet absorbed into a payslip, not cancelled. */
    public function isAvailableForDeduction(): bool
    {
        return $this->status === EmployeeAdvanceStatus::Paid && $this->deducted_in_payroll_item_id === null;
    }
}
