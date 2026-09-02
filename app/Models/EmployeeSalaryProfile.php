<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalaryPaymentFrequency;
use App\Enums\SalaryType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per salary change — history, not a mutable "current salary"
 * field. See EmployeeSalaryService::createProfile(): changing a salary
 * closes the currently-active profile and inserts a new one, it never
 * updates amounts on an existing row.
 */
class EmployeeSalaryProfile extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'salary_type',
        'base_salary',
        'currency',
        'payment_frequency',
        'payment_day',
        'effective_from',
        'effective_to',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'salary_type' => SalaryType::class,
        'base_salary' => 'decimal:2',
        'payment_frequency' => SalaryPaymentFrequency::class,
        'payment_day' => 'integer',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
