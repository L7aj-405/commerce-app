<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierAccount extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'user_id',
        'pin_code',
        'status',
        'daily_limit',
        'can_give_discounts',
        'max_discount_percent',
        'last_login_at',
        'locked_until',
        'failed_login_attempts',
    ];

    protected $hidden = [
        'pin_code',
    ];

    protected $casts = [
        'pin_code'              => 'hashed',
        'daily_limit'           => 'decimal:2',
        'can_give_discounts'    => 'boolean',
        'max_discount_percent'  => 'decimal:2',
        'last_login_at'         => 'datetime',
        'locked_until'          => 'datetime',
        'failed_login_attempts' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isLocked();
    }
}
