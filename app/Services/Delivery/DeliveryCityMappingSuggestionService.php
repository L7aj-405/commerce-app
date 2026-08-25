<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Support\Delivery\CityNameNormalizer;
use Illuminate\Support\Collection;

/**
 * Conservative internal-city -> provider-city matching, for the "Map all
 * suggested" UX. Only ever suggests UNMAPPED internal cities (an already
 * mapped city has nothing left to suggest — see mapped_cities instead).
 *
 * Never guesses: a suggestion is only "safe to auto-map" (can_auto_map) when
 * it's an exact/alias match, or a single clearly-best fuzzy match above the
 * confidence floor. Anything else (multiple close candidates, low
 * similarity, no candidates at all) is surfaced for manual review instead.
 */
class DeliveryCityMappingSuggestionService
{
    /** Auto-map floor for a fuzzy (non-exact, non-alias) match. */
    private const FUZZY_AUTO_MAP_THRESHOLD = 85.0;

    /** Below this, a fuzzy candidate isn't worth showing at all. */
    private const FUZZY_MIN_THRESHOLD = 60.0;

    /** Two candidates within this many points of each other are "too close to call". */
    private const AMBIGUITY_MARGIN = 5.0;

    /**
     * @return Collection<int, array{
     *     internal_city_id: string, internal_city_name: string,
     *     suggested_provider_city_id: ?string, suggested_provider_city_name: ?string,
     *     confidence: float, match_type: string, can_auto_map: bool, reason: string,
     * }>
     */
    /**
     * @param  bool  $includeMapped  false (default): only unmapped cities — a
     *   mapped city has nothing left to suggest for the normal "Map all
     *   suggested" flow. true: also suggest for already-mapped cities, used
     *   only when the caller explicitly wants to overwrite existing mappings.
     */
    public function suggestionsFor(Store $store, string $providerCode = 'ozon', bool $includeMapped = false): Collection
    {
        $providerCities = DeliveryProviderCity::query()
            ->where('store_id', $store->id)
            ->where('provider_code', $providerCode)
            ->get();

        $query = City::query()->where('country_code', 'MA')->where('is_active', true);

        if (! $includeMapped) {
            $mappedCityIds = CityDeliveryProviderMapping::query()
                ->where('store_id', $store->id)
                ->where('provider_code', $providerCode)
                ->pluck('city_id');

            $query->whereNotIn('id', $mappedCityIds);
        }

        $internalCities = $query->orderBy('name')->get();

        return $internalCities->map(fn (City $city) => $this->suggestFor($city, $providerCities))->values();
    }

    /** @return array{internal_city_id: string, internal_city_name: string, suggested_provider_city_id: ?string, suggested_provider_city_name: ?string, confidence: float, match_type: string, can_auto_map: bool, reason: string} */
    private function suggestFor(City $city, Collection $providerCities): array
    {
        $base = ['internal_city_id' => $city->id, 'internal_city_name' => $city->name];

        if ($providerCities->isEmpty()) {
            return $base + [
                'suggested_provider_city_id' => null, 'suggested_provider_city_name' => null,
                'confidence' => 0.0, 'match_type' => 'none', 'can_auto_map' => false,
                'reason' => 'No Ozon cities synced yet.',
            ];
        }

        $needle = CityNameNormalizer::normalize($city->name);

        $exact = $providerCities->first(fn (DeliveryProviderCity $pc) => CityNameNormalizer::normalize($pc->city_name) === $needle);

        if ($exact !== null) {
            return $base + [
                'suggested_provider_city_id' => $exact->id, 'suggested_provider_city_name' => $exact->city_name,
                'confidence' => 100.0, 'match_type' => 'exact', 'can_auto_map' => true,
                'reason' => 'Name matches exactly after normalization.',
            ];
        }

        $aliasGroup = CityNameNormalizer::aliasGroupFor($needle);

        if ($aliasGroup !== null) {
            $alias = $providerCities->first(
                fn (DeliveryProviderCity $pc) => in_array(CityNameNormalizer::normalize($pc->city_name), $aliasGroup, true)
            );

            if ($alias !== null) {
                return $base + [
                    'suggested_provider_city_id' => $alias->id, 'suggested_provider_city_name' => $alias->city_name,
                    'confidence' => 95.0, 'match_type' => 'alias', 'can_auto_map' => true,
                    'reason' => 'Matched via known spelling alias.',
                ];
            }
        }

        $scored = $providerCities
            ->map(fn (DeliveryProviderCity $pc) => ['pc' => $pc, 'score' => CityNameNormalizer::similarity($needle, CityNameNormalizer::normalize($pc->city_name))])
            ->sortByDesc('score')
            ->values();

        $best = $scored->first();
        $runnerUp = $scored->get(1);

        if ($best === null || $best['score'] < self::FUZZY_MIN_THRESHOLD) {
            return $base + [
                'suggested_provider_city_id' => null, 'suggested_provider_city_name' => null,
                'confidence' => round($best['score'] ?? 0.0, 1), 'match_type' => 'none', 'can_auto_map' => false,
                'reason' => 'No similar Ozon city found.',
            ];
        }

        $isAmbiguous = $runnerUp !== null
            && $runnerUp['score'] >= self::FUZZY_MIN_THRESHOLD
            && ($best['score'] - $runnerUp['score']) < self::AMBIGUITY_MARGIN;

        if ($isAmbiguous) {
            return $base + [
                'suggested_provider_city_id' => $best['pc']->id, 'suggested_provider_city_name' => $best['pc']->city_name,
                'confidence' => round($best['score'], 1), 'match_type' => 'ambiguous', 'can_auto_map' => false,
                'reason' => "Multiple similarly-close Ozon cities found (e.g. \"{$best['pc']->city_name}\" vs \"{$runnerUp['pc']->city_name}\") — pick one manually.",
            ];
        }

        $canAutoMap = $best['score'] >= self::FUZZY_AUTO_MAP_THRESHOLD;

        return $base + [
            'suggested_provider_city_id' => $best['pc']->id, 'suggested_provider_city_name' => $best['pc']->city_name,
            'confidence' => round($best['score'], 1), 'match_type' => 'fuzzy', 'can_auto_map' => $canAutoMap,
            'reason' => $canAutoMap
                ? 'High-confidence fuzzy match.'
                : 'Best match confidence is below the auto-map threshold — review before mapping.',
        ];
    }
}
