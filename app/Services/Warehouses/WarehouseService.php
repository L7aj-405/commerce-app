<?php

declare(strict_types=1);

namespace App\Services\Warehouses;

use App\Models\Warehouse;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Collection;

class WarehouseService
{
    /**
     * Create a warehouse
     */
    public function create(User $user, array $data): Warehouse
    {
        return Warehouse::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'country' => $data['country'] ?? null,
            'zip' => $data['zip'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false,
        ]);
    }

    /**
     * Update a warehouse
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $warehouse->update($data);
        return $warehouse->fresh();
    }

    /**
     * Assign warehouse to store
     */
    public function assignToStore(Warehouse $warehouse, Store $store, bool $isPrimary = false): void
    {
        if ($warehouse->stores()->where('store_id', $store->id)->exists()) {
            $warehouse->stores()->updateExistingPivot($store->id, [
                'is_primary' => $isPrimary,
            ]);
        } else {
            $warehouse->stores()->attach($store->id, [
                'is_primary' => $isPrimary,
            ]);
        }
    }

    /**
     * Remove warehouse from store
     */
    public function removeFromStore(Warehouse $warehouse, Store $store): void
    {
        $warehouse->stores()->detach($store->id);
    }

    /**
     * Get user warehouses
     */
    public function getUserWarehouses(User $user): Collection
    {
        return $user->warehouses()
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get store warehouses
     */
    public function getStoreWarehouses(Store $store): Collection
    {
        return $store->warehouses()
            ->where('is_active', true)
            ->orderBy('warehouse_store.is_primary', 'desc')
            ->get();
    }

    /**
     * Get primary warehouse for store
     */
    public function getPrimaryWarehouse(Store $store): ?Warehouse
    {
        return $store->warehouses()
            ->wherePivot('is_primary', true)
            ->first();
    }

    /**
     * Set default warehouse for user
     */
    public function setDefault(Warehouse $warehouse): void
    {
        Warehouse::where('user_id', $warehouse->user_id)
            ->update(['is_default' => false]);

        $warehouse->update(['is_default' => true]);
    }

    /**
     * Delete a warehouse
     */
    public function delete(Warehouse $warehouse): bool
    {
        // Remove from all stores
        $warehouse->stores()->detach();

        return (bool) $warehouse->delete();
    }
}