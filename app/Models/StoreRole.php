<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A store-scoped, admin-customisable role. Its `permissions` JSON column
 * holds a subset of App\Support\PermissionCatalog keys (or ['*'] for all).
 */
class StoreRole extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'description',
        'is_system',
        'is_locked',
        'permissions',
    ];

    protected $casts = [
        'is_system'   => 'boolean',
        'is_locked'   => 'boolean',
        'permissions' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (StoreRole $role): void {
            if (empty($role->slug)) {
                $role->slug = Str::slug($role->name);
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StoreInvitation::class);
    }

    /** @return array<int, string> */
    public function permissionList(): array
    {
        return $this->permissions ?? [];
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissionList();

        return in_array(PermissionCatalog::WILDCARD, $permissions, true)
            || in_array($permission, $permissions, true);
    }

    public function grantsDashboardAccess(): bool
    {
        return PermissionCatalog::grantsDashboardAccess($this->permissionList());
    }

    /**
     * Expand a wildcard role to the full catalogue, otherwise return the stored list.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(): array
    {
        return in_array(PermissionCatalog::WILDCARD, $this->permissionList(), true)
            ? PermissionCatalog::keys()
            : $this->permissionList();
    }

    public function isDeletable(): bool
    {
        return ! $this->is_system;
    }

    public function scopeForStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }
}
