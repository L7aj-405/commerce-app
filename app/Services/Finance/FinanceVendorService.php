<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinanceVendor;
use App\Models\Organization;

class FinanceVendorService
{
    public function create(Organization $organization, array $data): FinanceVendor
    {
        return FinanceVendor::query()->create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(FinanceVendor $vendor, array $data): FinanceVendor
    {
        $vendor->update([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $vendor->is_active,
        ]);

        return $vendor->refresh();
    }

    /** A vendor already referenced by an expense/recurring expense is deactivated instead of deleted. */
    public function deactivate(FinanceVendor $vendor): FinanceVendor
    {
        $vendor->update(['is_active' => false]);

        return $vendor->refresh();
    }

    public function delete(FinanceVendor $vendor): void
    {
        $vendor->delete();
    }
}
