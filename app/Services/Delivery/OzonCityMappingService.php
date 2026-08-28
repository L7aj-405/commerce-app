<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\OzonExpressConnector;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OzonCityMappingService
{
    /**
     * @return array{imported_count: int, updated_count: int, total_count: int}
     *
     * @throws ValidationException on connector failure or an unparseable response
     */
    public function syncCities(DeliveryConnection $connection): array
    {
        $connector = new OzonExpressConnector($connection);
        $result = $connector->listCities();

        if (! $result['ok']) {
            throw ValidationException::withMessages(['cities' => $result['error'] ?? 'Could not fetch Ozon cities.']);
        }

        $imported = 0;
        $updated = 0;

        foreach ($result['cities'] as $city) {
            $record = DeliveryProviderCity::query()->updateOrCreate(
                [
                    'store_id' => $connection->store_id,
                    'provider_code' => 'ozon',
                    'provider_city_id' => $city['provider_city_id'],
                ],
                [
                    'city_name' => $city['city_name'],
                    'city_ref' => $city['city_ref'] ?? null,
                    'delivered_price' => $city['delivered_price'] ?? null,
                    'returned_price' => $city['returned_price'] ?? null,
                    'refused_price' => $city['refused_price'] ?? null,
                    'raw_payload' => $city['raw'],
                ],
            );

            $record->wasRecentlyCreated ? $imported++ : $updated++;
        }

        return [
            'imported_count' => $imported,
            'updated_count' => $updated,
            'total_count' => $imported + $updated,
        ];
    }

    public function mapCity(Store $store, City $city, DeliveryProviderCity $providerCity): CityDeliveryProviderMapping
    {
        return CityDeliveryProviderMapping::query()->updateOrCreate(
            ['store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon'],
            ['delivery_provider_city_id' => $providerCity->id],
        );
    }

    /**
     * Maps every suggestion the algorithm judged safe (can_auto_map) and
     * leaves everything else — ambiguous, low-confidence, or no-match rows —
     * untouched for manual review.
     *
     * @return array{mapped_count: int, skipped_count: int, skipped_reasons: array<int, string>}
     */
    public function mapAllSuggested(
        Store $store,
        DeliveryCityMappingSuggestionService $suggestions,
        bool $overwrite = false,
    ): array {
        $rows = $suggestions->suggestionsFor($store, 'ozon', includeMapped: $overwrite);

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
                $skippedReasons[] = "{$row['internal_city_name']}: suggested city no longer exists.";

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
            ->where('provider_code', 'ozon')
            ->pluck('city_id');

        return City::query()
            ->where('country_code', 'MA')
            ->where('is_active', true)
            ->whereNotIn('id', $mappedCityIds)
            ->orderBy('name')
            ->get();
    }
}
