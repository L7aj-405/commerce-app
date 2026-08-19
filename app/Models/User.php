<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use App\Notifications\CustomVerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
        'role',
        'is_active',
        'settings',
        'metadata',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'phone_verified_at'       => 'datetime',
            'last_login_at'           => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'is_active'          => 'boolean',
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

    public function organizationsOwned(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_user_id');
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot(['role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    /** Every store belonging to an organization owned by this user. */
    public function organizationStores(): HasManyThrough
    {
        return $this->hasManyThrough(
            Store::class,
            Organization::class,
            'owner_user_id',
            'organization_id',
            'id',
            'id',
        );
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function ownedStores(): HasMany
    {
        return $this->hasMany(Store::class, 'user_id');
    }

    public function storeMembers(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function memberStores(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_members')
            ->withPivot(['role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    /** The only platform-wide role we still trust from users.role. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Legacy helpers kept for compatibility, but they are now STORE-CONTEXTUAL.
     * Operational access must never be inferred from the global users.role field.
     */
    public function isStoreAdmin(?Store $store = null): bool
    {
        $store ??= $this->getActiveStore();

        if ($store === null) {
            return false;
        }

        return $this->isPrivilegedFor($store)
            || $this->storeRoleFor($store)?->slug === 'administrator';
    }

    public function isManager(?Store $store = null): bool
    {
        $store ??= $this->getActiveStore();

        return $store !== null && $this->storeRoleFor($store)?->slug === 'manager';
    }

    public function isCashier(?Store $store = null): bool
    {
        $store ??= $this->getActiveStore();

        return $store !== null && $this->storeRoleFor($store)?->slug === 'cashier';
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function canManageTeam(): bool
    {
        return $this->hasStorePermission($this->getActiveStore(), 'team.manage');
    }

    public function organizationMembershipFor(?Organization $organization): ?OrganizationMember
    {
        if ($organization === null) {
            return null;
        }

        return $this->organizationMemberships()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, Organization> */
    public function managedAgencyOrganizations(): \Illuminate\Support\Collection
    {
        $owned = $this->organizationsOwned()
            ->where('type', Organization::TYPE_AGENCY)
            ->where('status', 'active')
            ->get();

        $member = Organization::query()
            ->where('type', Organization::TYPE_AGENCY)
            ->where('status', 'active')
            ->whereHas('memberships', fn ($q) => $q
                ->where('user_id', $this->id)
                ->where('is_active', true)
                ->whereIn('role', [OrganizationMember::ROLE_OWNER, OrganizationMember::ROLE_ADMIN]))
            ->get();

        return $owned->concat($member)->unique('id')->values();
    }

    public function canOperateClientOrganization(?Organization $organization): bool
    {
        if ($organization === null || ! $organization->isClient()) {
            return false;
        }

        $agencyIds = $this->managedAgencyOrganizations()->pluck('id');

        if ($agencyIds->isEmpty()) {
            return false;
        }

        return AgencyClientRelationship::query()
            ->whereIn('agency_organization_id', $agencyIds)
            ->where('client_organization_id', $organization->id)
            ->where('status', AgencyClientRelationship::STATUS_ACTIVE)
            ->exists();
    }

    public function canManageOrganization(?Organization $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        // Platform admins are not permanent members of every workspace. They
        // only receive organization-level privileges while an explicit, valid
        // support session is scoped to a store inside this organization.
        if ($this->isSuperAdmin()) {
            $support = app(\App\Services\SupportAccess::class)->current($this);

            return $support !== null && $support->organization_id === $organization->id;
        }

        if ($organization->owner_user_id === $this->id) {
            return true;
        }

        if ($this->organizationMembershipFor($organization)?->canManageOrganization() ?? false) {
            return true;
        }

        return $this->canOperateClientOrganization($organization);
    }

    /**
     * Organization owners/admins and the legacy store owner bypass granular
     * StoreRole checks. Platform super admins only bypass checks inside the one
     * store selected by an explicit support session.
     */
    public function isPrivilegedFor(?Store $store): bool
    {
        if ($store === null) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return app(\App\Services\SupportAccess::class)->permitsStore($this, $store);
        }

        // Once a store belongs to an organization, workspace membership is
        // authoritative. store.user_id is retained only for legacy compatibility
        // and must not create a permanent privilege bypass for an old creator.
        if ($store->organization !== null) {
            return $this->canManageOrganization($store->organization);
        }

        return $store->user_id === $this->id;
    }

    /** Slug of the seeded "Delivery agent" role (Str::slug('Delivery agent')). */
    public const DELIVERY_AGENT_ROLE = 'delivery-agent';

    /**
     * A pure delivery driver who belongs on the standalone mobile interface only
     * — never the manager dashboard. The login redirect and the `confine_driver`
     * middleware both key off this.
     *
     * Detection is role-first and robust:
     *   1. Assigned the "Delivery agent" role → definitively a driver, even if
     *      that role was later given extra permissions. This is the reliable
     *      switch: assign the role and the user is confined.
     *   2. Otherwise, any non-privileged member who can deliver but cannot open a
     *      manager order view (no orders.view, no orders.manage).
     *
     * A store owner / super admin is never a driver — they are privileged and
     * legitimately need the full dashboard, so "log in as the owner" will always
     * show the manager view. Use a member account assigned the Delivery agent
     * role to see the driver interface.
     *
     * @param  ?Store  $store  defaults to the user's active store
     */
    public function isDeliveryOnlyAgent(?Store $store = null): bool
    {
        $store ??= $this->getActiveStore();

        if ($store === null || $this->isPrivilegedFor($store)) {
            return false;
        }

        if ($this->storeRoleFor($store)?->slug === self::DELIVERY_AGENT_ROLE) {
            return true;
        }

        return $this->hasStorePermission($store, 'orders.deliver')
            && ! $this->hasStorePermission($store, 'orders.view')
            && ! $this->hasStorePermission($store, 'orders.manage');
    }

    public function storeMembershipFor(?Store $store): ?StoreMember
    {
        if ($store === null) {
            return null;
        }

        // In the V2 workspace model an active Organization membership is the
        // outer security boundary. A stale StoreMember row must not resurrect
        // access after the user has been removed/suspended from the workspace.
        if ($store->organization !== null && ! $store->organization->hasActiveMember($this)) {
            return null;
        }

        return $this->storeMembers()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with('storeRole')
            ->first();
    }

    public function storeRoleFor(?Store $store): ?StoreRole
    {
        return $this->storeMembershipFor($store)?->storeRole;
    }

    /**
     * Effective permission keys the user holds for a store. Privileged users
     * get the full catalogue; members get their role's expanded permissions.
     *
     * @return array<int, string>
     */
    public function permissionsForStore(?Store $store): array
    {
        if ($this->isPrivilegedFor($store)) {
            $permissions = \App\Support\PermissionCatalog::keys();

            // Platform support is a dashboard support channel, not cashier
            // impersonation. Keep POS behind its real PIN-authenticated flow.
            if ($this->isSuperAdmin()) {
                $permissions = array_values(array_diff($permissions, ['pos.access']));
            }

            return $permissions;
        }

        return $this->storeRoleFor($store)?->effectivePermissions() ?? [];
    }

    public function hasStorePermission(?Store $store, string $permission): bool
    {
        if ($this->isSuperAdmin() && $permission === 'pos.access') {
            return false;
        }

        if ($this->isPrivilegedFor($store)) {
            return true;
        }

        return $this->storeRoleFor($store)?->hasPermission($permission) ?? false;
    }

    public function canAccessDashboard(?Store $store = null): bool
    {
        $store ??= $this->getActiveStore();

        if ($store === null) {
            return false;
        }

        return \App\Support\PermissionCatalog::grantsDashboardAccess(
            $this->permissionsForStore($store),
        );
    }

    public function canAccessPos(?Store $store = null): bool
    {
        // Support mode intentionally targets the management dashboard only. POS
        // still requires a real cashier/PIN session and is not impersonated.
        if ($this->isSuperAdmin()) {
            return false;
        }

        $store ??= $this->getActiveStore();

        return $store !== null && $this->hasStorePermission($store, 'pos.access');
    }

    /**
     * Contextual access metadata shared with React/Inertia.
     *
     * @return array{roleName: ?string, roleSlug: ?string, canDashboard: bool, canPos: bool, canManageOrganization: bool}
     */
    public function accessProfileForStore(?Store $store): array
    {
        if ($store === null) {
            return [
                'roleName' => null,
                'roleSlug' => null,
                'canDashboard' => false,
                'canPos' => false,
                'canManageOrganization' => false,
            ];
        }

        $roleName = null;
        $roleSlug = null;

        if ($this->isSuperAdmin() && $this->isPrivilegedFor($store)) {
            $roleName = 'Platform support';
            $roleSlug = 'platform-support';
        } elseif ($store->organization?->owner_user_id === $this->id) {
            $roleName = 'Organization owner';
            $roleSlug = 'organization-owner';
        } elseif ($this->canOperateClientOrganization($store->organization)) {
            $roleName = 'Agency operator';
            $roleSlug = 'agency-operator';
        } elseif ($this->canManageOrganization($store->organization)) {
            $roleName = 'Organization admin';
            $roleSlug = 'organization-admin';
        } elseif ($store->organization === null && $store->user_id === $this->id) {
            $roleName = 'Store owner';
            $roleSlug = 'store-owner';
        } else {
            $role = $this->storeRoleFor($store);
            $roleName = $role?->name;
            $roleSlug = $role?->slug;
        }

        return [
            'roleName' => $roleName,
            'roleSlug' => $roleSlug,
            'canDashboard' => $this->canAccessDashboard($store),
            'canPos' => $this->canAccessPos($store),
            'canManageOrganization' => $this->canManageOrganization($store->organization),
        ];
    }

    /**
     * Every store the user can act in: the ones they own PLUS the ones they've
     * been added to as a team member.
     *
     * @return \Illuminate\Support\Collection<int, Store>
     */
    public function accessibleStores(): \Illuminate\Support\Collection
    {
        if ($this->isSuperAdmin()) {
            $supportStore = app(\App\Services\SupportAccess::class)->storeFor($this);

            return $supportStore !== null ? collect([$supportStore]) : collect();
        }

        // stores.user_id is only an access source for pre-organization legacy
        // rows. Organization-backed stores are governed by workspace membership.
        $owned = $this->ownedStores()->whereNull('organization_id')->get();

        $member = $this->memberStores()
            ->wherePivot('is_active', true)
            ->with('organization')
            ->get()
            ->filter(fn (Store $store): bool => $store->organization === null
                || $store->organization->hasActiveMember($this));

        // Organization owners/admins can act across every store in that
        // workspace even when they did not personally create the store row.
        $managedOrganizationIds = $this->organizationMemberships()
            ->where('is_active', true)
            ->whereIn('role', [OrganizationMember::ROLE_OWNER, OrganizationMember::ROLE_ADMIN])
            ->pluck('organization_id');

        $ownedOrganizationIds = $this->organizationsOwned()->pluck('id');

        $organizationStores = Store::query()
            ->whereIn('organization_id', $managedOrganizationIds->concat($ownedOrganizationIds)->unique()->values())
            ->get();

        $agencyIds = $this->managedAgencyOrganizations()->pluck('id');
        $clientOrganizationIds = AgencyClientRelationship::query()
            ->whereIn('agency_organization_id', $agencyIds)
            ->where('status', AgencyClientRelationship::STATUS_ACTIVE)
            ->pluck('client_organization_id');

        $agencyClientStores = $clientOrganizationIds->isEmpty()
            ? collect()
            : Store::query()->whereIn('organization_id', $clientOrganizationIds)->get();

        return $owned
            ->concat($member)
            ->concat($organizationStores)
            ->concat($agencyClientStores)
            ->unique('id')
            ->values();
    }

    /**
     * Resolve the user's active store. Prefers a session-scoped store_id
     * (set by the POS cashier login or the store switcher), then falls back to
     * the first store they can access — owned OR joined as a team member.
     */
    public function getActiveStore(): ?Store
    {
        $accessible     = $this->accessibleStores();
        $sessionStoreId = session('store_id') ?? session('pos.store_id');

        if ($sessionStoreId !== null) {
            $match = $accessible->firstWhere('id', $sessionStoreId);
            if ($match !== null) {
                return $match;
            }
        }

        return $accessible->first();
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
