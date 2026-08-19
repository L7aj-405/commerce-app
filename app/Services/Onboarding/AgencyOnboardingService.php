<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Models\Organization;
use App\Models\OrganizationServiceAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\OrganizationProvisioner;

/**
 * Backs the agency onboarding wizard (Step 8 / A1-A10). Reuses
 * AgencyWorkspaceService for everything client/warehouse/service related —
 * onboarding is a guided front door onto that existing engine, not a second
 * implementation of it.
 */
class AgencyOnboardingService
{
    public function __construct(
        private readonly OrganizationProvisioner $organizations,
        private readonly AgencyWorkspaceService $agencyWorkspace,
    ) {}

    /** A1 — the agency organization. Deliberately creates NO store. */
    public function saveOrganization(User $user, array $data): Organization
    {
        $organization = $user->organizationsOwned()->where('type', Organization::TYPE_AGENCY)->first();

        if ($organization === null) {
            $organization = $this->organizations->createOwnedOrganization($user, $data['name'], Organization::TYPE_AGENCY);
        } else {
            $organization->update(['name' => $data['name']]);
        }

        $organization->update([
            'settings' => array_merge($organization->settings ?? [], [
                'country'  => $data['country'],
                'currency' => $data['currency'],
                'phone'    => $data['phone'] ?? null,
                'logo'     => $data['logo'] ?? null,
            ]),
        ]);

        return $organization->fresh();
    }

    /**
     * A2 — services the agency offers, at the informational/menu level.
     * Picking/packing/dispatch are never accepted here — they are derived
     * from warehouse operation (App\Models\OrganizationServiceAssignment
     * deliberately omits them), never a toggle.
     *
     * @param  array<int,string>  $services
     */
    public function saveServices(Organization $organization, array $services): void
    {
        $organization->update([
            'settings' => array_merge($organization->settings ?? [], ['services_offered' => array_values($services)]),
        ]);
    }

    /**
     * A3 — agency-owned/operated warehouses. Only meaningful when
     * 'warehousing' is among the services offered.
     *
     * @param  array<int,array{name:string,city:?string,service_city_ids?:array<int,string>}>  $rows
     */
    public function saveWarehouses(Organization $organization, User $user, array $rows): void
    {
        foreach ($rows as $row) {
            if (blank($row['name'] ?? null)) {
                continue;
            }

            $warehouse = $this->agencyWorkspace->createAgencyWarehouse($organization, $user, [
                'name'    => $row['name'],
                'city'    => $row['city'] ?? null,
                'country' => $organization->settings['country'] ?? null,
            ]);

            $cityIds = $row['service_city_ids'] ?? [];
            if ($cityIds !== []) {
                $warehouse->serviceCities()->sync(collect($cityIds)->mapWithKeys(fn ($id) => [$id => ['priority' => 100, 'is_active' => true]])->all());
            }
        }
    }

    /** A4 + A5 — the first client, and its brand/store in the same step. */
    public function saveClient(Organization $agency, User $user, array $data): Organization
    {
        return $this->agencyWorkspace->createClient($agency, $user, [
            'client_name'   => $data['client_name'],
            'brand_name'    => $data['brand_name'],
            'business_type' => $data['business_type'],
            'country'       => $data['country'],
            'currency'      => $data['currency'],
        ]);
    }

    /** A6 — assign an existing agency warehouse to the client. */
    public function assignClientWarehouse(Organization $agency, Organization $client, Warehouse $warehouse, User $user): void
    {
        $this->agencyWorkspace->assignWarehouse($agency, $client, $warehouse, $user);
    }

    /** A6 (alternative) — the client operates its own warehouse instead. */
    public function createClientOwnedWarehouse(Organization $client, User $user, array $data): Warehouse
    {
        $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
            'user_id'                  => $user->id,
            'owner_organization_id'    => $client->id,
            'operator_organization_id' => $client->id,
            'name'                     => $data['name'],
            'city'                     => $data['city'] ?? null,
            'type'                     => Warehouse::TYPE_STANDARD,
            'country'                  => $client->settings['country'] ?? null,
            'is_active'                => true,
            'is_default'               => true,
        ]));

        $warehouse->accessibleOrganizations()->syncWithoutDetaching([$client->id => ['is_active' => true]]);

        $store = $client->stores()->first();
        $store?->warehouses()->syncWithoutDetaching([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

        return $warehouse;
    }

    /**
     * A7 — confirmation/support/delivery, self or agency. Picking, packing
     * and dispatch are intentionally not accepted keys here — see
     * OrganizationServiceAssignment::SERVICE_CODES.
     *
     * @param  array<string,string>  $assignments  service_code => 'self'|'agency'
     */
    public function saveClientServices(Organization $agency, Organization $client, User $user, array $assignments): void
    {
        foreach ($assignments as $service => $operator) {
            if (! in_array($service, OrganizationServiceAssignment::SERVICE_CODES, true)) {
                continue;
            }

            $this->agencyWorkspace->assignService($agency, $client, $service, $operator, $user);
        }
    }

    /** A8 + A9 — the client's inventory source and sales channels. */
    public function saveClientSetup(Store $store, array $data): void
    {
        $store->update([
            'settings' => array_merge($store->settings ?? [], [
                'inventory_source' => $data['inventory_source'] ?? null,
                'sales_channels'   => $data['sales_channels'] ?? [],
            ]),
        ]);
    }

    /** A10 — mark onboarding complete. No active store to select — the agency has none of its own. */
    public function complete(User $user): void
    {
        $user->update(['onboarding_completed_at' => now()]);
    }
}
