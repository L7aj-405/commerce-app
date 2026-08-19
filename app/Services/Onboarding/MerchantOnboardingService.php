<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Models\City;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backs the merchant onboarding wizard (Step 8 / M1-M6). Each method is one
 * wizard step, called from its own controller action so the flow is
 * resumable — progress lives in the rows themselves (does the user own an
 * Organization? does it have a Store? a Warehouse?), not in a separate
 * wizard-state column.
 */
class MerchantOnboardingService
{
    public function __construct(private readonly OrganizationProvisioner $organizations) {}

    /** M1 — create the merchant organization, or update it if onboarding is resumed. */
    public function saveOrganization(User $user, array $data): Organization
    {
        $organization = $user->organizationsOwned()->where('type', Organization::TYPE_MERCHANT)->first();

        if ($organization === null) {
            $organization = $this->organizations->createOwnedOrganization($user, $data['name'], Organization::TYPE_MERCHANT);
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

    /** M2 — the merchant's single brand/store. */
    public function saveStore(Organization $organization, User $user, array $data): Store
    {
        $store = $organization->stores()->first();

        if ($store !== null) {
            $store->update([
                'name' => $data['name'],
                'type' => $data['business_type'],
            ]);

            return $store->fresh();
        }

        $store = DB::transaction(function () use ($organization, $user, $data): Store {
            $store = Store::create([
                'organization_id' => $organization->id,
                'user_id'         => $user->id,
                'name'            => $data['name'],
                'slug'            => Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
                'type'            => $data['business_type'],
                'country'         => $organization->settings['country'] ?? null,
                'currency'        => $organization->settings['currency'] ?? null,
                'phone'           => $organization->settings['phone'] ?? null,
                'status'          => StoreStatus::Active->value,
                'settings'        => ['tax_rate' => 0, 'timezone' => 'UTC'],
            ]);

            $store->ensureDefaultRoles();

            StoreMember::create([
                'store_id'      => $store->id,
                'user_id'       => $user->id,
                'role'          => 'store_admin',
                'store_role_id' => $store->adminRole()?->id,
                'is_active'     => true,
                'joined_at'     => now(),
            ]);

            return $store;
        });

        return $store;
    }

    /**
     * M3 — where the merchant keeps stock.
     *
     * @param  array{mode:string, warehouses?:array<int,array{name:string,city:?string,service_city_ids?:array<int,string>}>}  $data
     */
    public function saveWarehouses(Organization $organization, Store $store, array $data): void
    {
        $mode = $data['mode'];

        // Idempotent: resuming onboarding must not create a second default
        // warehouse or duplicate rows for the same choice already saved.
        if ($organization->ownedWarehouses()->exists()) {
            $this->updateSettings($store, ['warehouse_mode' => $mode]);

            return;
        }

        if ($mode === 'default') {
            $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
                'user_id'                  => $store->user_id,
                'owner_organization_id'    => $organization->id,
                'operator_organization_id' => $organization->id,
                'name'                     => 'Default Warehouse',
                'type'                     => Warehouse::TYPE_STANDARD,
                'country'                  => $organization->settings['country'] ?? null,
                'is_active'                => true,
                'is_default'               => true,
            ]));

            $store->warehouses()->syncWithoutDetaching([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);
        } elseif ($mode === 'multiple') {
            foreach ($data['warehouses'] ?? [] as $priority => $row) {
                if (blank($row['name'] ?? null)) {
                    continue;
                }

                $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
                    'user_id'                  => $store->user_id,
                    'owner_organization_id'    => $organization->id,
                    'operator_organization_id' => $organization->id,
                    'name'                     => $row['name'],
                    'city'                     => $row['city'] ?? null,
                    'type'                     => Warehouse::TYPE_STANDARD,
                    'country'                  => $organization->settings['country'] ?? null,
                    'is_active'                => true,
                    'is_default'               => $priority === 0,
                ]));

                $store->warehouses()->syncWithoutDetaching([$warehouse->id => ['is_primary' => $priority === 0, 'priority' => $priority + 1]]);

                $cityIds = array_values(array_filter($row['service_city_ids'] ?? [], fn ($id) => City::query()->whereKey($id)->exists()));
                if ($cityIds !== []) {
                    $warehouse->serviceCities()->sync(collect($cityIds)->mapWithKeys(fn ($id) => [$id => ['priority' => 100, 'is_active' => true]])->all());
                }
            }
        }

        // 'none' creates nothing — just record the choice so the dashboard can
        // nudge the merchant later instead of silently having no warehouse.
        $this->updateSettings($store, ['warehouse_mode' => $mode]);
    }

    /** M4 + M5 — inventory source and sales channels, both pure setup choices. */
    public function saveSetup(Store $store, array $data): void
    {
        $this->updateSettings($store, [
            'inventory_source' => $data['inventory_source'] ?? null,
            'sales_channels'   => $data['sales_channels'] ?? [],
        ]);
    }

    /** M6 — mark onboarding complete and make this store the active one. */
    public function complete(User $user, Store $store): void
    {
        $user->update(['onboarding_completed_at' => now()]);
        request()->session()->put('store_id', $store->id);
    }

    private function updateSettings(Store $store, array $values): void
    {
        $store->update(['settings' => array_merge($store->settings ?? [], $values)]);
    }
}
