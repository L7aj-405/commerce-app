<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceVendor extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'phone',
        'address',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'vendor_id');
    }

    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(FinanceRecurringExpense::class, 'vendor_id');
    }

    public function isInUse(): bool
    {
        return $this->expenses()->exists() || $this->recurringExpenses()->exists();
    }
}
