<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'description',
        'type',
        'business_type',
        'status',
        'currency',
        'country',
        'address',
        'email',
        'phone',
        'logo',
        'cover_image',
        'settings',
        'business_rules',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type'           => StoreType::class,
            'status'         => StoreStatus::class,
            'settings'       => 'array',
            'business_rules' => 'array',
            'metadata'       => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Store $store): void {
            if (empty($store->slug)) {
                $store->slug = static::generateUniqueSlug($store->name);
            }
        });
    }

    private static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    // Relationships

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(StoreRole::class);
    }

    /**
     * Ensure this store has its default system roles. Idempotent — safe to call
     * on store creation and as a backfill for existing stores.
     *
     * @return \Illuminate\Support\Collection<string, StoreRole>
     */
    public function ensureDefaultRoles(): \Illuminate\Support\Collection
    {
        $roles = collect();

        foreach (\App\Support\PermissionCatalog::defaultRoles() as $definition) {
            $slug = Str::slug($definition['name']);

            $role = $this->roles()->firstOrNew(['slug' => $slug]);

            $role->name        = $definition['name'];
            $role->description = $definition['description'];
            $role->is_system   = true;
            $role->is_locked   = $definition['locked'];

            // Locked roles always mirror the catalogue; others keep any admin
            // customisation once created, and only seed permissions when new.
            if ($definition['locked'] || ! $role->exists) {
                $role->permissions = $definition['permissions'];
            }

            $role->save();

            $roles->put($role->slug, $role);
        }

        return $roles;
    }

    /** Resolve the default "Administrator" system role for this store. */
    public function adminRole(): ?StoreRole
    {
        return $this->roles()->where('slug', 'administrator')->first();
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(StoreMember::class)->where('is_active', true);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(StoreInvitation::class);
    }

    public function credentials(): HasOne
    {
        return $this->hasOne(StoreCredential::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_store')
            ->withPivot('is_primary', 'priority')
            ->withTimestamps();
    }

    public function customerInteractions(): HasMany
    {
        return $this->hasMany(CustomerInteraction::class);
    }

    public function activeConnections(): HasMany
    {
        return $this->hasMany(PlatformConnection::class)->where('status', 'active');
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StoreStatus::Active);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType(Builder $query, StoreType $type): Builder
    {
        return $query->where('type', $type);
    }

    // Helpers

    public function isOnline(): bool
    {
        return $this->type === StoreType::Online;
    }

    public function isPhysical(): bool
    {
        return $this->type === StoreType::Physical;
    }

    public function isHybrid(): bool
    {
        return $this->type === StoreType::Hybrid;
    }

    public function isActive(): bool
    {
        return $this->status === StoreStatus::Active;
    }

    public function getOrCreateCredentials(): StoreCredential
    {
        return $this->credentials ?? $this->credentials()->create([]);
    }

    public function hasActivePlatform(string $platform): bool
    {
        return $this->connections()
            ->where('platform', $platform)
            ->where('status', 'active')
            ->exists();
    }

    /** Returns the primary warehouse for this store, or null if none is set. */
    public function getDefaultWarehouse(): ?Warehouse
    {
        return $this->warehouses()->wherePivot('is_primary', true)->first();
    }

    /**
     * The location unsellable returned goods are written off to, creating and
     * attaching it on first use. Stock here is deliberately excluded from every
     * "on hand" total and is never pushed to a storefront.
     *
     * Always returns a Warehouse instance — never null.
     */
    public function getDamagedWarehouse(): Warehouse
    {
        $warehouse = $this->warehouses()->where('type', Warehouse::TYPE_DAMAGED)->first();

        if ($warehouse === null) {
            $warehouse = Warehouse::create([
                'user_id'    => $this->user_id,
                'owner_organization_id' => $this->organization_id,
                'operator_organization_id' => $this->organization_id,
                'name'       => "{$this->name} - Damaged Stock",
                'type'       => Warehouse::TYPE_DAMAGED,
                'is_active'  => true,
                'is_default' => false,
            ]);

            if ($this->organization_id !== null) {
                $warehouse->accessibleOrganizations()->syncWithoutDetaching([
                    $this->organization_id => ['is_active' => true],
                ]);
            }

            $this->warehouses()->attach($warehouse->id, [
                'is_primary' => false,
                'priority'   => 99,
            ]);
        }

        return $warehouse;
    }

    /** Returns all warehouses associated with this store. */
    public function getActiveWarehouses(): Collection
    {
        return $this->warehouses()->get();
    }

    /**
     * Link every warehouse this store's owner has that isn't attached to any
     * store yet. Warehouses created through the dashboard are owned by user_id
     * but were not auto-attached to the `warehouse_store` pivot the stock
     * features read from — so they never appeared in transfers/stock. This
     * self-heals that (idempotent), attaching only truly unassigned warehouses
     * so another store's warehouse is never stolen.
     */
    public function attachOwnerWarehouses(): void
    {
        $orphanIds = Warehouse::withoutTenancy(fn () => Warehouse::query()
            ->where('user_id', $this->user_id)
            ->whereDoesntHave('stores')
            ->pluck('id'));

        if ($orphanIds->isEmpty()) {
            return;
        }

        $priority = (int) (DB::table('warehouse_store')
            ->where('store_id', $this->id)
            ->max('priority') ?? 0);

        $payload = [];
        foreach ($orphanIds as $id) {
            $priority++;
            $payload[$id] = ['is_primary' => false, 'priority' => $priority];
        }

        $this->warehouses()->attach($payload);
    }

    /** Make the given (already-attached) warehouse this store's primary. */
    public function markPrimaryWarehouse(Warehouse $warehouse): void
    {
        DB::table('warehouse_store')->where('store_id', $this->id)->update(['is_primary' => false]);

        DB::table('warehouse_store')
            ->where('store_id', $this->id)
            ->where('warehouse_id', $warehouse->id)
            ->update(['is_primary' => true]);
    }

    public function getTotalProductCount(): int
    {
        return $this->products()->count();
    }

    /** Value of sellable inventory only — written-off damaged stock is worth nothing. */
    public function getTotalStockValue(): float
    {
        return (float) $this->products()
            ->join('stocks', 'products.id', '=', 'stocks.product_id')
            ->join('warehouses', 'stocks.warehouse_id', '=', 'warehouses.id')
            ->where('warehouses.type', Warehouse::TYPE_STANDARD)
            ->selectRaw('SUM(stocks.quantity * products.price) as total')
            ->value('total') ?? 0.0;
    }

    /**
     * Returns the primary warehouse, creating and attaching a default one if none exists.
     * Always returns a Warehouse instance — never null.
     */
    public function getPrimaryWarehouse(): Warehouse
    {
        $warehouse = $this->getDefaultWarehouse();

        if ($warehouse === null) {
            $warehouse = Warehouse::create([
                'user_id'    => $this->user_id,
                'owner_organization_id' => $this->organization_id,
                'operator_organization_id' => $this->organization_id,
                'name'       => "{$this->name} - Default Warehouse",
                'type'       => Warehouse::TYPE_STANDARD,
                'address'    => '',
                'city'       => '',
                'state'      => '',
                'zip'        => '',
                'country'    => '',
                'phone'      => '',
                'is_default' => true,
            ]);

            if ($this->organization_id !== null) {
                $warehouse->accessibleOrganizations()->syncWithoutDetaching([
                    $this->organization_id => ['is_active' => true],
                ]);
            }

            $this->warehouses()->attach($warehouse->id, [
                'is_primary' => true,
                'priority'   => 1,
            ]);
        }

        return $warehouse;
    }

    public function posSessions(): HasMany
    {
        return $this->hasMany(PosSession::class);
    }
}
