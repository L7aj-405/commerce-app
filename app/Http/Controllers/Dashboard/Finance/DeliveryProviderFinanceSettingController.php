<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\DeliveryProviderCityFeeRequest;
use App\Http\Requests\Finance\DeliveryProviderFinanceSettingRequest;
use App\Models\City;
use App\Models\DeliveryProvider;
use App\Models\DeliveryProviderCity;
use App\Models\DeliveryProviderCityFee;
use App\Models\DeliveryProviderFinanceSetting;
use App\Models\FinanceAccount;
use App\Models\Organization;
use App\Support\Delivery\CityNameNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simple per-organization finance setup for external delivery providers —
 * default fees + COD payout schedule + bank account, plus manual city fee
 * overrides. See DeliveryProviderFinanceSetting/DeliveryProviderCityFee
 * docblocks. Providers render as a compact list on the index page; per-
 * provider detail (COD settings / default fees / city exceptions) lives
 * behind the "Configure" action in the UI, never all expanded at once.
 */
class DeliveryProviderFinanceSettingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DeliveryProviderFinanceSetting::class);

        $organization = $request->user()->getActiveStore()?->organization;

        $providers = DeliveryProvider::query()
            ->where('is_active', true)
            ->where('code', '!=', DeliveryProvider::INTERNAL)
            ->orderBy('name')
            ->get();

        $settings = $organization !== null
            ? DeliveryProviderFinanceSetting::query()->where('organization_id', $organization->id)->with('bankAccount:id,name')->get()->keyBy('delivery_provider_id')
            : collect();

        $cityFees = $organization !== null
            ? DeliveryProviderCityFee::query()->where('organization_id', $organization->id)->orderBy('city_name')->get()->groupBy('delivery_provider_id')
            : collect();

        return Inertia::render('Dashboard/Finance/DeliveryProviders/Index', [
            'providers' => $providers->map(function (DeliveryProvider $provider) use ($organization, $settings, $cityFees) {
                $providerCityFees = $cityFees->get($provider->id, collect())->values();

                return [
                    'id' => $provider->id,
                    'code' => $provider->code,
                    'name' => $provider->name,
                    'settings' => $settings->get($provider->id),
                    'city_fees' => $providerCityFees,
                    'city_fee_count' => $providerCityFees->where('is_active', true)->count(),
                    'city_options' => $organization !== null ? $this->cityOptionsFor($organization, $provider) : ['source' => 'canonical_city', 'options' => []],
                ];
            })->values(),
            'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'can' => [
                'manage' => $request->user()->can('manage', DeliveryProviderFinanceSetting::class),
                'custom_city' => $request->user()->isPrivilegedFor($request->user()->getActiveStore()),
            ],
        ]);
    }

    public function update(DeliveryProviderFinanceSettingRequest $request, DeliveryProvider $provider): RedirectResponse
    {
        $organization = $request->user()->getActiveStore()?->organization;
        abort_if($organization === null, 422, 'No active organization.');

        $validated = $request->validated();
        $validated['is_cod_enabled'] = $request->boolean('is_cod_enabled', true);
        $validated['is_active'] = $request->boolean('is_active', true);

        DeliveryProviderFinanceSetting::query()->updateOrCreate(
            ['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id],
            $validated,
        );

        return back()->with('success', "{$provider->name} finance settings saved.");
    }

    public function storeCityFee(DeliveryProviderCityFeeRequest $request, DeliveryProvider $provider): RedirectResponse
    {
        $organization = $request->user()->getActiveStore()?->organization;
        abort_if($organization === null, 422, 'No active organization.');

        $validated = $request->validated();
        $city = $this->resolveCitySnapshot($validated);

        $this->assertNoDuplicateActiveCityFee($organization, $provider, $city);

        $validated['is_active'] = $request->boolean('is_active', true);
        unset($validated['city_id'], $validated['provider_city_id'], $validated['custom_city_name']);

        DeliveryProviderCityFee::query()->create([
            'organization_id' => $organization->id,
            'delivery_provider_id' => $provider->id,
            ...$city,
            ...$validated,
        ]);

        return back()->with('success', 'City fee added.');
    }

    public function updateCityFee(DeliveryProviderCityFeeRequest $request, DeliveryProviderCityFee $cityFee): RedirectResponse
    {
        $this->authorize('manage', DeliveryProviderFinanceSetting::class);

        $validated = $request->validated();
        $city = $this->resolveCitySnapshot($validated);

        $this->assertNoDuplicateActiveCityFee($cityFee->organization, $cityFee->provider, $city, excludeId: $cityFee->id);

        $validated['is_active'] = $request->boolean('is_active', true);
        unset($validated['city_id'], $validated['provider_city_id'], $validated['custom_city_name']);

        $cityFee->update([...$city, ...$validated]);

        return back()->with('success', 'City fee updated.');
    }

    public function destroyCityFee(Request $request, DeliveryProviderCityFee $cityFee): RedirectResponse
    {
        $this->authorize('manage', DeliveryProviderFinanceSetting::class);

        // Deactivate rather than delete — a settlement item may already
        // reference this fee's snapshot (fee_source metadata); soft removal
        // keeps that history intact and is instantly reversible.
        $cityFee->update(['is_active' => false]);

        return back()->with('success', 'City fee deactivated.');
    }

    /**
     * Resolves the validated city selection down to what actually gets
     * stored — id(s) as the matching source of truth, city_name/
     * provider_city_code as a display snapshot only. Exactly one of
     * provider_city_id/city_id/custom_city_name is present by the time
     * validation passes (see DeliveryProviderCityFeeRequest).
     *
     * @return array{city_id: ?string, provider_city_id: ?string, city_name: ?string, provider_city_code: ?string}
     */
    private function resolveCitySnapshot(array $validated): array
    {
        if (! empty($validated['provider_city_id'])) {
            $providerCity = DeliveryProviderCity::withoutTenancy(fn () => DeliveryProviderCity::find($validated['provider_city_id']));

            return [
                'city_id' => null,
                'provider_city_id' => $providerCity?->id,
                'city_name' => $providerCity?->city_name,
                'provider_city_code' => $providerCity?->city_ref ?? $providerCity?->provider_city_id,
            ];
        }

        if (! empty($validated['city_id'])) {
            $city = City::find($validated['city_id']);

            return [
                'city_id' => $city?->id,
                'provider_city_id' => null,
                'city_name' => $city?->name,
                'provider_city_code' => null,
            ];
        }

        return [
            'city_id' => null,
            'provider_city_id' => null,
            'city_name' => trim((string) $validated['custom_city_name']),
            'provider_city_code' => null,
        ];
    }

    /**
     * "Prevent duplicate active city fee for the same provider + city" —
     * matched by the SAME identity resolveCitySnapshot() just resolved
     * (provider_city_id > city_id > normalized city_name), so a duplicate
     * can never sneak in behind two different city-selection paths for the
     * same real city.
     *
     * @param  array{city_id: ?string, provider_city_id: ?string, city_name: ?string, provider_city_code: ?string}  $city
     *
     * @throws ValidationException
     */
    private function assertNoDuplicateActiveCityFee(Organization $organization, DeliveryProvider $provider, array $city, ?string $excludeId = null): void
    {
        $base = fn () => DeliveryProviderCityFee::query()
            ->where('organization_id', $organization->id)
            ->where('delivery_provider_id', $provider->id)
            ->where('is_active', true)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));

        $duplicate = match (true) {
            $city['provider_city_id'] !== null => $base()->where('provider_city_id', $city['provider_city_id'])->exists(),
            $city['city_id'] !== null => $base()->where('city_id', $city['city_id'])->exists(),
            default => $base()->whereNull('provider_city_id')->whereNull('city_id')->whereNotNull('city_name')->get()
                ->contains(fn (DeliveryProviderCityFee $fee) => CityNameNormalizer::normalize((string) $fee->city_name) === CityNameNormalizer::normalize((string) $city['city_name'])),
        };

        if ($duplicate) {
            throw ValidationException::withMessages(['city_id' => "An active city fee for {$city['city_name']} already exists for this provider."]);
        }
    }

    /**
     * Searchable city options for the Add City Fee UI. Prefers the
     * provider's own synced cities (App\Models\DeliveryProviderCity, across
     * every store in this organization — that sync is store-scoped) since
     * that's the exact identifier a shipment ends up carrying
     * (Shipment.city_id); falls back to the internal canonical city list
     * (App\Models\City) when the provider has nothing synced yet.
     *
     * @return array{source: 'provider_city'|'canonical_city', options: array<int, array<string, mixed>>}
     */
    private function cityOptionsFor(Organization $organization, DeliveryProvider $provider): array
    {
        $storeIds = $organization->stores()->pluck('id');

        $providerCities = DeliveryProviderCity::withoutTenancy(fn () => DeliveryProviderCity::query()
            ->whereIn('store_id', $storeIds)
            ->where('provider_code', $provider->code)
            ->orderBy('city_name')
            ->get(['id', 'city_name', 'city_ref', 'provider_city_id', 'district_name']));

        if ($providerCities->isNotEmpty()) {
            return [
                'source' => 'provider_city',
                'options' => $providerCities
                    ->unique('city_name')
                    ->map(fn (DeliveryProviderCity $c) => [
                        'value' => $c->id,
                        'label' => $c->city_name,
                        'sublabel' => $c->district_name && $c->district_name !== $c->city_name ? $c->district_name : null,
                        'code' => $c->city_ref ?? $c->provider_city_id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $cities = City::query()->where('country_code', 'MA')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'region']);

        return [
            'source' => 'canonical_city',
            'options' => $cities->map(fn (City $c) => [
                'value' => $c->id,
                'label' => $c->name,
                'sublabel' => $c->region,
                'code' => null,
            ])->values()->all(),
        ];
    }
}
