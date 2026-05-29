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
use Illuminate\Support\Str;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'type',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** Returns all warehouses associated with this store. */
    public function getActiveWarehouses(): Collection
    {
        return $this->warehouses()->get();
    }

    public function getTotalProductCount(): int
    {
        return $this->products()->count();
    }

    public function getTotalStockValue(): float
    {
        return (float) $this->products()
            ->join('stocks', 'products.id', '=', 'stocks.product_id')
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
                'name'       => "{$this->name} - Default Warehouse",
                'address'    => '',
                'city'       => '',
                'state'      => '',
                'zip'        => '',
                'country'    => '',
                'phone'      => '',
                'is_default' => true,
            ]);

            $this->warehouses()->attach($warehouse->id, [
                'is_primary' => true,
                'priority'   => 1,
            ]);
        }

        return $warehouse;
    }
}
