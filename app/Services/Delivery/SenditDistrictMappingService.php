<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\SenditConnector;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Support\Delivery\CityNameNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sendit's district-sync + internal-city mapping — mirrors
 * OzonCityMappingService's shape exactly (same DeliveryProviderCity /
 * CityDeliveryProviderMapping tables, provider_code='sendit' instead of
 * 'ozon'), so it plugs into the SAME generic DeliveryCityMappingResolver /
 * DeliveryCityMappingSuggestionService used for Ozon — a Sendit district IS
 * a "provider city" as far as those two services are concerned.
 */
class SenditDistrictMappingService
{
    /**
     * Major Moroccan cities used purely as a "did the sync actually pick up
     * the well-known cities" sanity check for the UI warning — never used
     * for matching/mapping logic (that stays CityNameNormalizer's job).
     *
     * @var array<int, string>
     */
    private const MAJOR_CITIES = [
        'Casablanca', 'Rabat', 'Marrakech', 'Fes', 'Meknes', 'Tanger',
        'Agadir', 'Oujda', 'Kenitra', 'Tetouan', 'Safi',
    ];

    /**
     * @return array{
     *     imported_count: int, updated_count: int, total_count: int,
     *     distinct_cities_count: int, pages_fetched: int, pickup_district_used: string,
     *     pickup_cities_synced: int,
     * }
     *
     * @throws ValidationException on connector failure or an unparseable response
     */
    public function syncDistricts(DeliveryConnection $connection): array
    {
        $pickupDistrictId = $connection->setting('default_pickup_district_id');
        $pickupDistrictId = filled($pickupDistrictId) ? (string) $pickupDistrictId : SenditConnector::DEFAULT_PICKUP_DISTRICT_ID;

        $connector = new SenditConnector($connection);
        $result = $connector->listDistricts($pickupDistrictId);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['districts' => $result['error'] ?? 'Could not fetch Sendit districts.']);
        }

        $imported = 0;
        $updated = 0;

        foreach ($result['cities'] as $district) {
            $record = DeliveryProviderCity::query()->updateOrCreate(
                [
                    'store_id' => $connection->store_id,
                    'provider_code' => 'sendit',
                    'provider_city_id' => $district['provider_city_id'],
                ],
                [
                    'city_name' => $district['city_name'],
                    'district_name' => $district['district_name'] ?? null,
                    'name_arabic' => $district['name_arabic'] ?? null,
                    'price' => $district['price'] ?? null,
                    'delais' => $district['delais'] ?? null,
                    // Informational only here — the row-level "pickup_district"
                    // flag /districts happens to carry. The dropdown-facing
                    // TRUTH is set below, from the dedicated pickup-cities
                    // endpoint, which overrides whatever this loop wrote.
                    'is_pickup_district' => $district['is_pickup_district'] ?? null,
                    'raw_payload' => $district['raw'],
                ],
            );

            $record->wasRecentlyCreated ? $imported++ : $updated++;
        }

        $pickupCitiesSynced = $this->syncPickupCities($connector, $connection);

        $distinctCitiesCount = DeliveryProviderCity::query()
            ->where('store_id', $connection->store_id)
            ->where('provider_code', 'sendit')
            ->distinct()
            ->count('city_name');

        return [
            'imported_count' => $imported,
            'updated_count' => $updated,
            'total_count' => $imported + $updated,
            'distinct_cities_count' => $distinctCitiesCount,
            'pages_fetched' => $result['pages_fetched'],
            'pickup_district_used' => $result['pickup_district_used'],
            'pickup_cities_synced' => $pickupCitiesSynced,
        ];
    }

    /**
     * GET /districts/pickup-cities is the SOLE source of truth for the
     * "Default pickup district" dropdown — never derived from the delivery
     * districts endpoint's per-row `pickup_district` flag (see
     * syncDistricts()'s doc comment). Every row this endpoint returns is
     * marked is_pickup_district=true; every OTHER Sendit row for this store
     * is explicitly cleared to false, so a stale true from a prior sync (or
     * from the unreliable per-row flag above) never lingers in the dropdown.
     *
     * A failed pickup-cities call is non-fatal — the district sync itself
     * already succeeded — and, deliberately, an EMPTY-but-successful
     * response is treated as "nothing to update" (guards against clearing
     * every flag store-wide on a transient empty page).
     */
    private function syncPickupCities(SenditConnector $connector, DeliveryConnection $connection): int
    {
        $result = $connector->listPickupCities();

        if (! $result['ok'] || $result['cities'] === []) {
            return 0;
        }

        $pickupIds = [];

        foreach ($result['cities'] as $pickup) {
            DeliveryProviderCity::query()->updateOrCreate(
                [
                    'store_id' => $connection->store_id,
                    'provider_code' => 'sendit',
                    'provider_city_id' => $pickup['provider_city_id'],
                ],
                [
                    'city_name' => $pickup['city_name'],
                    'is_pickup_district' => true,
                ],
            );

            $pickupIds[] = $pickup['provider_city_id'];
        }

        DeliveryProviderCity::query()
            ->where('store_id', $connection->store_id)
            ->where('provider_code', 'sendit')
            ->whereNotIn('provider_city_id', $pickupIds)
            ->update(['is_pickup_district' => false]);

        return count($pickupIds);
    }

    /**
     * Which of MAJOR_CITIES are missing from the currently-synced Sendit
     * districts, for the "some cities may be missing" UI warning — a
     * cheap sanity check, not a data-integrity guarantee.
     *
     * @return array<int, string>
     */
    public function missingMajorCities(Store $store): array
    {
        $syncedNames = DeliveryProviderCity::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->pluck('city_name')
            ->map(fn (string $name) => CityNameNormalizer::normalize($name))
            ->all();

        return collect(self::MAJOR_CITIES)
            ->reject(fn (string $city) => in_array(CityNameNormalizer::normalize($city), $syncedNames, true))
            ->values()
            ->all();
    }

    /** @return array{ok: bool, districts: array<int, array<string, mixed>>, error?: string} the synced-and-flagged pickup districts, for the "default pickup district" picker. */
    public function pickupDistricts(Store $store): array
    {
        $rows = DeliveryProviderCity::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->where('is_pickup_district', true)
            ->orderBy('city_name')
            ->get(['id', 'provider_city_id', 'city_name']);

        return ['ok' => true, 'districts' => $rows->toArray()];
    }

    public function mapCity(Store $store, City $city, DeliveryProviderCity $providerCity): CityDeliveryProviderMapping
    {
        return CityDeliveryProviderMapping::query()->updateOrCreate(
            ['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'sendit'],
            ['delivery_provider_city_id' => $providerCity->id],
        );
    }

    /**
     * @return array{mapped_count: int, skipped_count: int, skipped_reasons: array<int, string>}
     */
    public function mapAllSuggested(
        Store $store,
        DeliveryCityMappingSuggestionService $suggestions,
        bool $overwrite = false,
    ): array {
        $rows = $suggestions->suggestionsFor($store, 'sendit', includeMapped: $overwrite);

        $mapped = 0;
        $skipped = 0;
        $skippedReasons = [];

        foreach ($rows as $row) {
            if (! $row['can_auto_map']) {
                $skipped++;
                $skippedReasons[] = "{$row['internal_city_name']}: {$row['reason']}";

                continue;
            }

            $city = City::find($row['internal_city_id']);
            $providerCity = DeliveryProviderCity::where('store_id', $store->id)->find($row['suggested_provider_city_id']);

            if ($city === null || $providerCity === null) {
                $skipped++;
                $skippedReasons[] = "{$row['internal_city_name']}: suggested district no longer exists.";

                continue;
            }

            $this->mapCity($store, $city, $providerCity);
            $mapped++;
        }

        return ['mapped_count' => $mapped, 'skipped_count' => $skipped, 'skipped_reasons' => $skippedReasons];
    }

    /** @return Collection<int, City> */
    public function unmappedCities(Store $store): Collection
    {
        $mappedCityIds = CityDeliveryProviderMapping::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->pluck('city_id');

        return City::query()
            ->where('country_code', 'MA')
            ->where('is_active', true)
            ->whereNotIn('id', $mappedCityIds)
            ->orderBy('name')
            ->get();
    }
}
