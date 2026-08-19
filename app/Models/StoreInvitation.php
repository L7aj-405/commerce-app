<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StoreInvitation extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'invited_by',
        'email',
        'role',
        'store_role_id',
        'token',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function storeRole(): BelongsTo
    {
        return $this->belongsTo(StoreRole::class, 'store_role_id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at?->isPast() === true;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')->orWhere('expires_at', '<=', now());
        });
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
