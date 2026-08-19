<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    public const TYPE_MERCHANT = 'merchant';
    public const TYPE_AGENCY = 'agency';
    public const TYPE_CLIENT = 'client';

    use HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'owner_user_id',
        'type',
        'name',
        'slug',
        'status',
        'settings',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Organization $organization): void {
            if (empty($organization->slug)) {
                $organization->slug = static::generateUniqueSlug($organization->name);
            }
        });
    }

    private static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $i = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }


    public function agencyRelationships(): HasMany
    {
        return $this->hasMany(AgencyClientRelationship::class, 'agency_organization_id');
    }

    public function clientRelationships(): HasMany
    {
        return $this->hasMany(AgencyClientRelationship::class, 'client_organization_id');
    }

    public function clientOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'agency_client_organizations', 'agency_organization_id', 'client_organization_id')
            ->withPivot(['status', 'settings'])
            ->withTimestamps();
    }

    public function managingAgencies(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'agency_client_organizations', 'client_organization_id', 'agency_organization_id')
            ->withPivot(['status', 'settings'])
            ->withTimestamps();
    }

    public function ownedWarehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'owner_organization_id');
    }

    public function operatedWarehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'operator_organization_id');
    }

    public function accessibleWarehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_organization_access')
            ->withPivot(['is_active', 'settings'])
            ->withTimestamps();
    }

    public function serviceAssignments(): HasMany
    {
        return $this->hasMany(OrganizationServiceAssignment::class, 'client_organization_id');
    }

    public function isAgency(): bool { return $this->type === self::TYPE_AGENCY; }
    public function isClient(): bool { return $this->type === self::TYPE_CLIENT; }
    public function isMerchant(): bool { return $this->type === self::TYPE_MERCHANT; }


    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function hasActiveMember(User $user): bool
    {
        return $this->owner_user_id === $user->id
            || $this->memberships()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
    }

    public function userCanManage(User $user): bool
    {
        return $user->canManageOrganization($this);
    }
}
