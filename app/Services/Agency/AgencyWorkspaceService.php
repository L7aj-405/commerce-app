<?php

declare(strict_types=1);

namespace App\Services\Agency;

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Models\AgencyClientRelationship;
use App\Models\Organization;
use App\Models\OrganizationServiceAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class AgencyWorkspaceService
{
    public function createClient(Organization $agency, User $actor, array $data): Organization
    {
        abort_unless($agency->isAgency() && $actor->canManageOrganization($agency), 403);

        return DB::transaction(function () use ($agency, $actor, $data): Organization {
            $client = Organization::create([
                'owner_user_id' => null,
                'type' => Organization::TYPE_CLIENT,
                'name' => $data['client_name'],
                'status' => 'active',
            ]);

            AgencyClientRelationship::create([
                'agency_organization_id' => $agency->id,
                'client_organization_id' => $client->id,
                'status' => AgencyClientRelationship::STATUS_ACTIVE,
            ]);

            $store = Store::create([
                'organization_id' => $client->id,
                'user_id' => $actor->id,
                'name' => $data['brand_name'],
                'type' => $data['business_type'] ?? StoreType::Online->value,
                'status' => StoreStatus::Active->value,
                'country' => $data['country'],
                'currency' => $data['currency'],
                'settings' => ['timezone' => 'Africa/Casablanca'],
            ]);
            $store->ensureDefaultRoles();

            foreach ([OrganizationServiceAssignment::SERVICE_CONFIRMATION, OrganizationServiceAssignment::SERVICE_CUSTOMER_SUPPORT, OrganizationServiceAssignment::SERVICE_DELIVERY] as $service) {
                OrganizationServiceAssignment::create([
                    'client_organization_id' => $client->id,
                    'service_code' => $service,
                    'operator_organization_id' => $client->id,
                    'is_active' => true,
                ]);
            }

            return $client;
        });
    }

    public function createAgencyWarehouse(Organization $agency, User $actor, array $data): Warehouse
    {
        abort_unless($agency->isAgency() && $actor->canManageOrganization($agency), 403);

        return Warehouse::withoutTenancy(function () use ($agency, $actor, $data): Warehouse {
            $warehouse = Warehouse::create(array_merge($data, [
                'user_id' => $actor->id,
                'owner_organization_id' => $agency->id,
                'operator_organization_id' => $agency->id,
                'type' => Warehouse::TYPE_STANDARD,
                'is_active' => true,
                'is_default' => false,
            ]));
            $warehouse->accessibleOrganizations()->syncWithoutDetaching([
                $agency->id => ['is_active' => true],
            ]);
            return $warehouse;
        });
    }

    public function assignWarehouse(Organization $agency, Organization $client, Warehouse $warehouse, User $actor): void
    {
        $this->assertClient($agency, $client, $actor);
        abort_unless($warehouse->owner_organization_id === $agency->id || $warehouse->operator_organization_id === $agency->id, 403);

        Warehouse::withoutTenancy(function () use ($client, $warehouse): void {
            $warehouse->accessibleOrganizations()->syncWithoutDetaching([
                $client->id => ['is_active' => true],
            ]);
            foreach ($client->stores as $store) {
                $store->warehouses()->syncWithoutDetaching([$warehouse->id => ['is_primary' => false, 'priority' => 50]]);
            }
        });
    }

    public function assignService(Organization $agency, Organization $client, string $service, string $operator, User $actor): void
    {
        $this->assertClient($agency, $client, $actor);
        abort_unless(in_array($service, OrganizationServiceAssignment::SERVICE_CODES, true), 422);
        $operatorOrg = $operator === 'agency' ? $agency : $client;

        OrganizationServiceAssignment::updateOrCreate(
            ['client_organization_id' => $client->id, 'service_code' => $service],
            ['operator_organization_id' => $operatorOrg->id, 'is_active' => true],
        );
    }

    private function assertClient(Organization $agency, Organization $client, User $actor): void
    {
        abort_unless($actor->canManageOrganization($agency), 403);
        abort_unless(AgencyClientRelationship::query()->where('agency_organization_id', $agency->id)->where('client_organization_id', $client->id)->where('status', 'active')->exists(), 403);
    }
}
