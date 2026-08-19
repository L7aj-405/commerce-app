<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Organization;
use App\Models\OrganizationServiceAssignment;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Onboarding\AgencyOnboardingService;
use App\Support\OnboardingOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgencyOnboardingController extends Controller
{
    public function __construct(private readonly AgencyOnboardingService $onboarding) {}

    public function show(Request $request): Response
    {
        $user         = $request->user();
        $organization = $this->agencyFor($user);
        $warehouses   = $organization?->ownedWarehouses()->get() ?? collect();
        $client       = $organization?->clientOrganizations()->first();
        $clientStore  = $client?->stores()->first();

        return Inertia::render('Onboarding/Agency', [
            'progress' => [
                'organization' => $organization === null ? null : array_merge($organization->only(['id', 'name']), [
                    'country'  => $organization->settings['country'] ?? null,
                    'currency' => $organization->settings['currency'] ?? null,
                    'phone'    => $organization->settings['phone'] ?? null,
                ]),
                'services_offered' => $organization?->settings['services_offered'] ?? [],
                'warehouses'       => $warehouses->map->only(['id', 'name', 'city'])->values(),
                'client'           => $client?->only(['id', 'name']),
                'client_store'     => $clientStore?->only(['id', 'name', 'type']),
                'client_warehouse_assigned' => $client !== null && $client->accessibleWarehouses()->exists(),
                'client_services'  => $client?->serviceAssignments()->pluck('operator_organization_id', 'service_code')
                    ->map(fn ($operatorId) => $operatorId === $client->id ? 'self' : 'agency'),
                'client_setup' => $clientStore ? [
                    'inventory_source' => $clientStore->settings['inventory_source'] ?? null,
                    'sales_channels'   => $clientStore->settings['sales_channels'] ?? [],
                ] : null,
            ],
            'countries'        => OnboardingOptions::COUNTRIES,
            'businessTypes'    => OnboardingOptions::BUSINESS_TYPES,
            'platforms'        => OnboardingOptions::PLATFORMS,
            'inventorySources' => OnboardingOptions::INVENTORY_SOURCES,
            'agencyServices'   => OnboardingOptions::AGENCY_SERVICES,
            'cities'           => City::query()->where('country_code', $organization?->settings['country'] ?? 'MA')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeOrganization(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'country'  => ['required', 'string', 'size:2'],
            'currency' => ['required', 'string', 'size:3'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'logo'     => ['nullable', 'string', 'max:2048'],
        ]);

        $this->onboarding->saveOrganization($request->user(), $data);

        return back();
    }

    public function storeServices(Request $request): RedirectResponse
    {
        $organization = $this->requireAgency($request);

        $data = $request->validate([
            'services'   => ['nullable', 'array'],
            'services.*' => ['string', Rule::in(array_column(OnboardingOptions::AGENCY_SERVICES, 'value'))],
        ]);

        $this->onboarding->saveServices($organization, $data['services'] ?? []);

        return back();
    }

    public function storeWarehouses(Request $request): RedirectResponse
    {
        $organization = $this->requireAgency($request);

        $data = $request->validate([
            'warehouses'                      => ['nullable', 'array'],
            'warehouses.*.name'               => ['required_with:warehouses', 'string', 'max:255'],
            'warehouses.*.city'               => ['nullable', 'string', 'max:120'],
            'warehouses.*.service_city_ids'   => ['nullable', 'array'],
            'warehouses.*.service_city_ids.*' => ['string'],
        ]);

        $this->onboarding->saveWarehouses($organization, $request->user(), $data['warehouses'] ?? []);

        return back();
    }

    public function storeClient(Request $request): RedirectResponse
    {
        $organization = $this->requireAgency($request);

        $data = $request->validate([
            'client_name'   => ['required', 'string', 'max:255'],
            'brand_name'    => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::in(OnboardingOptions::businessTypeValues())],
            'country'       => ['required', 'string', 'size:2'],
            'currency'      => ['required', 'string', 'size:3'],
        ]);

        $this->onboarding->saveClient($organization, $request->user(), $data);

        return back();
    }

    public function assignClientWarehouse(Request $request): RedirectResponse
    {
        $organization = $this->requireAgency($request);
        $client       = $this->requireClient($organization);

        $data = $request->validate([
            'mode'         => ['required', Rule::in(['assign_agency', 'client_owned'])],
            'warehouse_id' => ['required_if:mode,assign_agency', 'nullable', 'string'],
            'name'         => ['required_if:mode,client_owned', 'nullable', 'string', 'max:255'],
            'city'         => ['nullable', 'string', 'max:120'],
        ]);

        if ($data['mode'] === 'assign_agency') {
            $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id', $organization->id)->findOrFail($data['warehouse_id']));
            $this->onboarding->assignClientWarehouse($organization, $client, $warehouse, $request->user());
        } else {
            $this->onboarding->createClientOwnedWarehouse($client, $request->user(), $data);
        }

        return back();
    }

    public function storeClientServices(Request $request): RedirectResponse
    {
        $organization = $this->requireAgency($request);
        $client       = $this->requireClient($organization);

        $data = $request->validate([
            'assignments'   => ['required', 'array'],
            'assignments.*' => ['string', Rule::in(['self', 'agency'])],
        ]);

        // Only confirmation/customer_support/delivery are ever accepted keys
        // here — picking/packing/dispatch are derived from warehouse
        // operation, never an independent toggle (enforced again inside the
        // service, this is belt-and-suspenders at the validation layer).
        $assignments = array_intersect_key($data['assignments'], array_flip(OrganizationServiceAssignment::SERVICE_CODES));

        $this->onboarding->saveClientServices($organization, $client, $request->user(), $assignments);

        return back();
    }

    public function storeClientSetup(Request $request): RedirectResponse
    {
        $organization = $this->requireAgency($request);
        $client       = $this->requireClient($organization);
        $store        = $client->stores()->first();
        abort_if($store === null, 422, 'Client has no store yet.');

        $data = $request->validate([
            'inventory_source' => ['nullable', Rule::in(OnboardingOptions::inventorySourceValues())],
            'sales_channels'   => ['nullable', 'array'],
            'sales_channels.*' => ['string', Rule::in(OnboardingOptions::platformValues())],
        ]);

        $this->onboarding->saveClientSetup($store, $data);

        return back();
    }

    public function complete(Request $request): RedirectResponse
    {
        $this->requireAgency($request);

        $this->onboarding->complete($request->user());

        return redirect()->route('agency.clients.index')->with('success', 'Agency workspace ready.');
    }

    private function agencyFor(\App\Models\User $user): ?Organization
    {
        return $user->organizationsOwned()->where('type', Organization::TYPE_AGENCY)->first();
    }

    private function requireAgency(Request $request): Organization
    {
        $organization = $this->agencyFor($request->user());
        abort_if($organization === null, 422, 'Complete the agency organization step first.');

        return $organization;
    }

    private function requireClient(Organization $organization): Organization
    {
        $client = $organization->clientOrganizations()->first();
        abort_if($client === null, 422, 'Add a client first.');

        return $client;
    }
}
