<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\AgencyClientRelationship;
use App\Models\Organization;
use App\Models\OrganizationServiceAssignment;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Agency\AgencyWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    private function agency(Request $request): Organization
    {
        return $request->user()->managedAgencyOrganizations()->firstOrFail();
    }

    public function index(Request $request): Response
    {
        $agency = $this->agency($request);
        $clients = $agency->clientOrganizations()->wherePivot('status', 'active')->with('stores:id,organization_id,name,status')->orderBy('name')->get();
        return Inertia::render('Agency/Clients', ['agency' => $agency->only(['id','name']), 'clients' => $clients]);
    }

    public function store(Request $request, AgencyWorkspaceService $service): RedirectResponse
    {
        $data = $request->validate([
            'client_name' => ['required','string','max:255'],
            'brand_name' => ['required','string','max:255'],
            'country' => ['required','string','size:2'],
            'currency' => ['required','string','size:3'],
        ]);
        $client = $service->createClient($this->agency($request), $request->user(), $data);
        return redirect()->route('agency.clients.show', $client)->with('success', 'Client workspace created.');
    }

    public function show(Request $request, Organization $client): Response
    {
        $agency = $this->agency($request);
        abort_unless(AgencyClientRelationship::query()->where('agency_organization_id',$agency->id)->where('client_organization_id',$client->id)->where('status','active')->exists(), 403);
        $client->load('stores:id,organization_id,name,status');
        $warehouses = Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id',$agency->id)->orderBy('name')->get(['id','name','city','owner_organization_id','operator_organization_id']));
        $assigned = $client->accessibleWarehouses()->wherePivot('is_active', true)->pluck('warehouses.id');
        $services = $client->serviceAssignments()->get()->mapWithKeys(fn ($s) => [$s->service_code => $s->operator_organization_id === $agency->id ? 'agency' : 'self']);
        return Inertia::render('Agency/ClientShow', compact('agency','client','warehouses','assigned','services'));
    }

    public function open(Request $request, Organization $client, Store $store): RedirectResponse
    {
        $agency = $this->agency($request);
        abort_unless($request->user()->canOperateClientOrganization($client) && $store->organization_id === $client->id, 403);
        $request->session()->put('store_id', $store->id);
        return redirect('/dashboard');
    }

    public function assignWarehouse(Request $request, Organization $client, Warehouse $warehouse, AgencyWorkspaceService $service): RedirectResponse
    {
        $service->assignWarehouse($this->agency($request), $client, $warehouse, $request->user());
        return back()->with('success', 'Warehouse assigned.');
    }

    public function assignService(Request $request, Organization $client, string $serviceCode, AgencyWorkspaceService $service): RedirectResponse
    {
        $data = $request->validate(['operator' => ['required','in:self,agency']]);
        $service->assignService($this->agency($request), $client, $serviceCode, $data['operator'], $request->user());
        return back()->with('success', 'Service operator updated.');
    }
}
