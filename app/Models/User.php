<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use App\Notifications\CustomVerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'settings',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'phone_verified_at'  => 'datetime',
            'last_login_at'      => 'datetime',
            'two_factor_enabled' => 'boolean',
            'status'             => UserStatus::class,
            'settings'           => 'array',
            'metadata'           => 'array',
        ];
    }

    /**
     * Merge stored settings with defaults so callers always get a complete config.
     * The 'array' cast handles JSON encoding on set; this accessor handles decoding + defaults on get.
     */
    public function getSettingsAttribute(mixed $value): array
    {
        $defaults = [
            'language'      => 'en',
            'timezone'      => 'Africa/Casablanca',
            'notifications' => true,
        ];

        $stored = is_string($value) ? (json_decode($value, true) ?? []) : [];

        return array_merge($defaults, $stored);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function activeStores(): HasMany
    {
        return $this->hasMany(Store::class)->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function isBanned(): bool
    {
        return $this->status === UserStatus::Banned;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function sendEmailVerificationNotification(): void
    {
        Notification::send($this, new CustomVerifyEmailNotification());
    }

    public function recordLogin(): void
    {
        $this->last_login_at = now();
        $this->save();
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => Str::substr($word, 0, 1))
            ->implode('');
    }
    public function warehouses(): HasMany
{
    return $this->hasMany(Warehouse::class);
}
}
