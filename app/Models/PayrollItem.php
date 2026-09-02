<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayrollItemStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's salary-due line for one payroll period. Calculating a
 * period writes/refreshes these — see PayrollService::calculate() — never a
 * finance_transaction. Only PayrollService::pay() books one, and only once
 * (idempotent — see its own docblock).
 */
class PayrollItem extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'payroll_period_id',
        'employee_id',
        'salary_profile_id',
        'base_amount',
        'bonus_amount',
        'deduction_amount',
        'advance_deduction_amount',
        'net_amount',
        'currency',
        'status',
        'account_id',
        'paid_at',
        'paid_by',
        'reference',
        'notes',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'advance_deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'status' => PayrollItemStatus::class,
        'paid_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salaryProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryProfile::class, 'salary_profile_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isPaid(): bool
    {
        return $this->status === PayrollItemStatus::Paid;
    }

    public function isCancelled(): bool
    {
        return $this->status === PayrollItemStatus::Cancelled;
    }
}
