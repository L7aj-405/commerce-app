<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        $warehouses = $store === null ? collect() : Warehouse::query()
            ->with(['ownerOrganization:id,name,type', 'operatorOrganization:id,name,type'])
            ->orderBy('name')->get()
            ->map(fn (Warehouse $warehouse) => array_merge(
                $warehouse->only(['id','name','location','address','city','country','is_default','is_active']),
                [
                    'can_manage' => $store->organization_id === null || in_array($store->organization_id, [$warehouse->owner_organization_id, $warehouse->operator_organization_id], true),
                    // Minimal fields only — never the organization's settings/metadata.
                    'owner_organization' => $warehouse->ownerOrganization?->only(['id', 'name', 'type']),
                    'operator_organization' => $warehouse->operatorOrganization?->only(['id', 'name', 'type']),
                ]
            ));

        return Inertia::render('Dashboard/Warehouses/Index', [
            'warehouses' => $warehouses,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Dashboard/Warehouses/Create', ['cities' => $this->cities()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'location'   => ['nullable', 'string', 'max:255'],
            'address'    => ['nullable', 'string', 'max:255'],
            'city'       => ['nullable', 'string', 'max:120'],
            'state'      => ['nullable', 'string', 'max:120'],
            'country'    => ['nullable', 'string', 'max:80'],
            'zip'        => ['nullable', 'string', 'max:20'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'email'      => ['nullable', 'email', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
            'service_city_ids' => ['nullable', 'array'],
            'service_city_ids.*' => ['string', 'exists:cities,id'],
        ]);

        $serviceCityIds = $validated['service_city_ids'] ?? [];
        unset($validated['service_city_ids']);
        $makeDefault = (bool) ($validated['is_default'] ?? false);

        $store = $request->user()->getActiveStore();
        $organizationId = $store?->organization_id;

        $warehouse = Warehouse::create(array_merge($validated, [
            'user_id'    => $request->user()->id,
            'owner_organization_id' => $organizationId,
            'operator_organization_id' => $organizationId,
            'is_active'  => true,
            'is_default' => $makeDefault,
        ]));

        // A warehouse belongs to the active store through warehouse_store. Attach
        // this exact row immediately instead of sweeping every orphan warehouse
        // owned by the user into the current store.
        if ($store !== null) {
            $priority = (int) ($store->warehouses()->max('warehouse_store.priority') ?? 0) + 1;

            $store->warehouses()->syncWithoutDetaching([
                $warehouse->id => [
                    'is_primary' => $makeDefault,
                    'priority' => $priority,
                ],
            ]);

            if ($organizationId !== null) {
                $warehouse->accessibleOrganizations()->syncWithoutDetaching([$organizationId => ['is_active' => true]]);
            }

            $warehouse->serviceCities()->sync(collect($serviceCityIds)->mapWithKeys(fn ($id) => [$id => ['priority' => 100, 'is_active' => true]])->all());

            if ($makeDefault) {
                $store->markPrimaryWarehouse($warehouse);
            }
        }

        return redirect()->route('dashboard.warehouses.index')->with('success', 'Warehouse created.');
    }

    public function edit(Request $request, Warehouse $warehouse): Response
    {
        $this->ensureWarehouseBelongsToActiveStore($request, $warehouse);

        return Inertia::render('Dashboard/Warehouses/Edit', [
            'warehouse' => array_merge($warehouse->only(['id', 'name', 'location', 'address', 'city', 'state', 'country', 'zip', 'phone', 'email', 'is_default', 'is_active']), ['service_city_ids' => $warehouse->serviceCities()->wherePivot('is_active', true)->pluck('cities.id')->all()]),
            'cities' => $this->cities(),
        ]);
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->ensureWarehouseBelongsToActiveStore($request, $warehouse);

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'location'   => ['nullable', 'string', 'max:255'],
            'address'    => ['nullable', 'string', 'max:255'],
            'city'       => ['nullable', 'string', 'max:120'],
            'state'      => ['nullable', 'string', 'max:120'],
            'country'    => ['nullable', 'string', 'max:80'],
            'zip'        => ['nullable', 'string', 'max:20'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'email'      => ['nullable', 'email', 'max:255'],
            'is_active'  => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'service_city_ids' => ['nullable', 'array'],
            'service_city_ids.*' => ['string', 'exists:cities,id'],
        ]);

        $serviceCityIds = $validated['service_city_ids'] ?? [];
        unset($validated['service_city_ids']);
        $warehouse->update($validated);
        $warehouse->serviceCities()->sync(collect($serviceCityIds)->mapWithKeys(fn ($id) => [$id => ['priority' => 100, 'is_active' => true]])->all());

        return back()->with('success', 'Warehouse updated.');
    }

    private function cities(): \Illuminate\Support\Collection
    {
        return City::query()->where('country_code', 'MA')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'region']);
    }

    private function ensureWarehouseBelongsToActiveStore(Request $request, Warehouse $warehouse): void
    {
        $store = $request->user()->getActiveStore();

        $organizationId = $store?->organization_id;
        $canManage = $store !== null && (
            ($organizationId !== null && in_array($organizationId, [$warehouse->owner_organization_id, $warehouse->operator_organization_id], true))
            || ($organizationId === null && $store->warehouses()->whereKey($warehouse->id)->exists())
        );

        abort_if(
            ! $canManage,
            403,
            'Warehouse does not belong to the active store.',
        );
    }
}
