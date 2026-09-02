<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeEmploymentStatus;
use App\Enums\EmployeeRoleType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the business pays — with or without a system login (`user_id`
 * nullable). NOT the same concept as a StoreMember/User: a StoreMember is
 * about DASHBOARD ACCESS AND PERMISSIONS, an Employee is about PAYROLL. The
 * two may or may not point at the same person; linking one to a `user_id`
 * never grants or changes that user's permissions, and removing dashboard
 * access never touches the employee record. See EmployeeService for the
 * "one user can't be linked to more than one ACTIVE employee in the same
 * organization" rule (an application-level check, not a DB constraint —
 * deliberately, since the business may occasionally want to allow it).
 */
class Employee extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'user_id',
        'employee_code',
        'first_name',
        'last_name',
        'display_name',
        'phone',
        'email',
        'role_type',
        'employment_status',
        'hired_at',
        'left_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'role_type' => EmployeeRoleType::class,
        'employment_status' => EmployeeEmploymentStatus::class,
        'hired_at' => 'date:Y-m-d',
        'left_at' => 'date:Y-m-d',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salaryProfiles(): HasMany
    {
        return $this->hasMany(EmployeeSalaryProfile::class)->orderByDesc('effective_from');
    }

    /** The one profile currently in force, if any — see EmployeeSalaryService::createProfile(). */
    public function activeSalaryProfile(): HasMany
    {
        return $this->hasMany(EmployeeSalaryProfile::class)->where('is_active', true);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function isActive(): bool
    {
        return $this->employment_status === EmployeeEmploymentStatus::Active;
    }
}
