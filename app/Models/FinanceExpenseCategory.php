<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceExpenseCategory extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'group',
        'color',
        'icon',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(FinanceExpense::class, 'category_id');
    }

    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(FinanceRecurringExpense::class, 'category_id');
    }

    public function isInUse(): bool
    {
        return $this->expenses()->exists() || $this->recurringExpenses()->exists();
    }
}
