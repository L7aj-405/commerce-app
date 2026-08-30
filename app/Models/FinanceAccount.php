<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceAccountType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'name',
        'type',
        'currency',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'type' => FinanceAccountType::class,
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'account_id');
    }

    public function isInUse(): bool
    {
        return $this->transactions()->exists();
    }
}
