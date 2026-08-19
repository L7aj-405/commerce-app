<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Organization;
use App\Services\Onboarding\MerchantOnboardingService;
use App\Support\OnboardingOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MerchantOnboardingController extends Controller
{
    public function __construct(private readonly MerchantOnboardingService $onboarding) {}

    public function show(Request $request): Response
    {
        $user         = $request->user();
        $organization = $user->organizationsOwned()->where('type', Organization::TYPE_MERCHANT)->first();
        $store        = $organization?->stores()->first();

        return Inertia::render('Onboarding/Merchant', [
            'progress' => [
                'organization' => $organization === null ? null : array_merge($organization->only(['id', 'name']), [
                    'country'  => $organization->settings['country'] ?? null,
                    'currency' => $organization->settings['currency'] ?? null,
                    'phone'    => $organization->settings['phone'] ?? null,
                ]),
                'store' => $store?->only(['id', 'name', 'type']),
                'warehouse_mode' => $store?->settings['warehouse_mode'] ?? null,
                // Only present once storeSetup() has actually run (even with
                // empty choices) — distinguishes "not visited yet" from
                // "visited and explicitly skipped", which array_key_exists on
                // a `?? null` read can't tell apart.
                'setup' => $store !== null && array_key_exists('inventory_source', $store->settings ?? []) ? [
                    'inventory_source' => $store->settings['inventory_source'] ?? null,
                    'sales_channels'   => $store->settings['sales_channels'] ?? [],
                ] : null,
            ],
            'businessTypes'     => OnboardingOptions::BUSINESS_TYPES,
            'countries'         => OnboardingOptions::COUNTRIES,
            'platforms'         => OnboardingOptions::PLATFORMS,
            'inventorySources'  => OnboardingOptions::INVENTORY_SOURCES,
            'cities'            => City::query()->where('country_code', $organization?->settings['country'] ?? 'MA')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
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

    public function storeStore(Request $request): RedirectResponse
    {
        $organization = $this->requireOrganization($request);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::in(OnboardingOptions::businessTypeValues())],
        ]);

        $this->onboarding->saveStore($organization, $request->user(), $data);

        return back();
    }

    public function storeWarehouses(Request $request): RedirectResponse
    {
        $organization = $this->requireOrganization($request);
        $store        = $this->requireStore($organization);

        $data = $request->validate([
            'mode'                            => ['required', Rule::in(['default', 'multiple', 'none'])],
            'warehouses'                      => ['nullable', 'array'],
            'warehouses.*.name'               => ['required_with:warehouses', 'string', 'max:255'],
            'warehouses.*.city'               => ['nullable', 'string', 'max:120'],
            'warehouses.*.service_city_ids'   => ['nullable', 'array'],
            'warehouses.*.service_city_ids.*' => ['string'],
        ]);

        $this->onboarding->saveWarehouses($organization, $store, $data);

        return back();
    }

    public function storeSetup(Request $request): RedirectResponse
    {
        $organization = $this->requireOrganization($request);
        $store        = $this->requireStore($organization);

        $data = $request->validate([
            'inventory_source'   => ['nullable', Rule::in(OnboardingOptions::inventorySourceValues())],
            'sales_channels'     => ['nullable', 'array'],
            'sales_channels.*'   => ['string', Rule::in(OnboardingOptions::platformValues())],
        ]);

        $this->onboarding->saveSetup($store, $data);

        return back();
    }

    public function complete(Request $request): RedirectResponse
    {
        $organization = $this->requireOrganization($request);
        $store        = $this->requireStore($organization);

        $this->onboarding->complete($request->user(), $store);

        return redirect()->route('dashboard')->with('success', 'Welcome aboard! Your store is ready.');
    }

    private function requireOrganization(Request $request): Organization
    {
        $organization = $request->user()->organizationsOwned()->where('type', Organization::TYPE_MERCHANT)->first();
        abort_if($organization === null, 422, 'Complete the organization step first.');

        return $organization;
    }

    private function requireStore(Organization $organization): \App\Models\Store
    {
        $store = $organization->stores()->first();
        abort_if($store === null, 422, 'Complete the brand/store step first.');

        return $store;
    }
}
