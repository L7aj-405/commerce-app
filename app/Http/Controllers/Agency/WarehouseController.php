<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Warehouse;
use App\Services\Agency\AgencyWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(Request $request): Response
    {
        $agency = $request->user()->managedAgencyOrganizations()->firstOrFail();
        $warehouses = Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id',$agency->id)->with(['accessibleOrganizations:id,name','serviceCities:id,name'])->orderBy('name')->get());
        $cities = City::query()->where('country_code','MA')->where('is_active',true)->orderBy('name')->get(['id','name','region']);
        return Inertia::render('Agency/Warehouses', ['agency' => $agency->only(['id','name']), 'warehouses' => $warehouses, 'cities' => $cities]);
    }

    public function store(Request $request, AgencyWorkspaceService $service): RedirectResponse
    {
        $agency = $request->user()->managedAgencyOrganizations()->firstOrFail();
        $data = $request->validate([
            'name' => ['required','string','max:255'], 'city' => ['nullable','string','max:120'],
            'address' => ['nullable','string','max:255'], 'country' => ['nullable','string','max:80'],
            'service_city_ids' => ['nullable','array'], 'service_city_ids.*' => ['string','exists:cities,id'],
        ]);
        $serviceCityIds = $data['service_city_ids'] ?? []; unset($data['service_city_ids']);
        $warehouse = $service->createAgencyWarehouse($agency, $request->user(), $data);
        $warehouse->serviceCities()->sync(collect($serviceCityIds)->mapWithKeys(fn ($id) => [$id => ['priority'=>100,'is_active'=>true]])->all());
        return back()->with('success', 'Agency warehouse created.');
    }

    public function serviceAreas(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $agency = $request->user()->managedAgencyOrganizations()->firstOrFail();
        abort_unless($warehouse->owner_organization_id === $agency->id || $warehouse->operator_organization_id === $agency->id, 403);
        $data = $request->validate(['service_city_ids'=>['nullable','array'],'service_city_ids.*'=>['string','exists:cities,id']]);
        $warehouse->serviceCities()->sync(collect($data['service_city_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => ['priority'=>100,'is_active'=>true]])->all());
        return back()->with('success','Warehouse service areas updated.');
    }
}
