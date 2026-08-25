<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Support\Delivery\CityNameNormalizer;
use App\Support\OrderAddressSummary;

/**
 * Resolves which Ozon (or any provider) city a packed order should ship to,
 * trying progressively looser strategies rather than a single rigid lookup
 * by `shipping_city_id`. This is the fix for a real gap: the Confirmation
 * Desk does not require every order to have a resolved `shipping_city_id`
 * (an order can be confirmed without picking a city from the list), so a
 * lookup keyed ONLY on that column reports "not mapped" even when the
 * mapping genuinely exists under the platform's raw city text — the
 * mapping UI operates on named cities, not on that FK.
 *
 * Priority, first one that finds a usable Ozon city wins:
 *   1. confirmed_city_id  — order.shipping_city_id -> its own mapping.
 *   2. normalized_city_name — the raw platform city text, accent/case/
 *      punctuation-normalized, matched against the internal city list, then
 *      that city's mapping.
 *   3. alias — same normalized text, matched via the small alias dictionary
 *      shared with DeliveryCityMappingSuggestionService (Casa/Casablanca etc).
 *   4. direct_provider_city — the normalized text (or an alias of it)
 *      matches a synced Ozon city NAME directly, with no internal mapping
 *      involved at all. Last resort only; an internal mapping is always
 *      preferred when one exists.
 */
class DeliveryCityMappingResolver
{
    /** Fuzzy floor for the "suggested match" hint in an unresolved error — a hint only, never auto-applied. */
    private const SUGGESTION_THRESHOLD = 70.0;

    /**
     * @return array{
     *     resolved: bool,
     *     internal_city_id: ?string, internal_city_name: ?string,
     *     provider_city_id: ?string, provider_city_name: ?string,
     *     provider_city_record_id: ?string,
     *     resolution_source: 'confirmed_city_id'|'normalized_city_name'|'alias'|'direct_provider_city'|'unmapped',
     *     raw_city_text: ?string, normalized_city_text: ?string,
     *     suggested_internal_city_id: ?string, suggested_internal_city_name: ?string,
     *     error: ?string,
     * }
     */
    public function resolve(Order $order, DeliveryConnection $connection): array
    {
        $providerCode = $connection->provider_code;
        $storeId = $order->store_id;

        $rawCity = $this->rawCityText($order);
        $normalized = $rawCity !== null ? CityNameNormalizer::normalize($rawCity) : null;

        // 1. Confirmed/internal city id — the order was explicitly confirmed
        // against a specific internal city.
        if ($order->shipping_city_id !== null) {
            $mapping = $this->mappingForCityId($storeId, $providerCode, $order->shipping_city_id);

            if ($mapping?->providerCity !== null) {
                return $this->resolved(
                    'confirmed_city_id', $order->shippingCity, $mapping->providerCity,
                    $rawCity, $normalized,
                );
            }
        }

        $internalCity = null;

        if ($normalized !== null) {
            // 2. Normalized city name -> internal city -> its mapping.
            $internalCity = $this->findInternalCityByNormalizedName($normalized);

            if ($internalCity !== null) {
                $mapping = $this->mappingForCityId($storeId, $providerCode, $internalCity->id);

                if ($mapping?->providerCity !== null) {
                    return $this->resolved('normalized_city_name', $internalCity, $mapping->providerCity, $rawCity, $normalized);
                }
            }

            // 3. Alias fallback — try every other spelling in the same group.
            $aliasGroup = CityNameNormalizer::aliasGroupFor($normalized);

            if ($aliasGroup !== null) {
                foreach ($aliasGroup as $alias) {
                    if ($alias === $normalized) {
                        continue;
                    }

                    $aliasCity = $this->findInternalCityByNormalizedName($alias);

                    if ($aliasCity === null) {
                        continue;
                    }

                    $internalCity ??= $aliasCity;
                    $mapping = $this->mappingForCityId($storeId, $providerCode, $aliasCity->id);

                    if ($mapping?->providerCity !== null) {
                        return $this->resolved('alias', $aliasCity, $mapping->providerCity, $rawCity, $normalized);
                    }
                }
            }

            // 4. Direct provider-city fallback — no internal mapping exists,
            // but the text matches a synced Ozon city by name directly.
            $candidates = array_unique(array_merge([$normalized], $aliasGroup ?? []));
            $directProviderCity = $this->findProviderCityByAnyNormalizedName($storeId, $providerCode, $candidates);

            if ($directProviderCity !== null) {
                return $this->resolved('direct_provider_city', $internalCity, $directProviderCity, $rawCity, $normalized);
            }
        }

        // Only worth a fuzzy hint when nothing exact/alias matched at all.
        $suggested = $internalCity === null && $normalized !== null ? $this->suggestInternalCity($normalized) : null;

        return [
            'resolved' => false,
            'internal_city_id' => $internalCity?->id,
            'internal_city_name' => $internalCity?->name,
            'provider_city_id' => null,
            'provider_city_name' => null,
            'provider_city_record_id' => null,
            'resolution_source' => 'unmapped',
            'raw_city_text' => $rawCity,
            'normalized_city_text' => $normalized,
            'suggested_internal_city_id' => $suggested?->id,
            'suggested_internal_city_name' => $suggested?->name,
            'error' => $this->buildError($rawCity, $internalCity, $suggested),
        ];
    }

    /**
     * The order's own city text, in priority order: an already-known
     * internal city (shipping_city_id resolved but simply unmapped — no
     * fuzzy matching needed, we already know exactly which city it is),
     * then the platform's original raw address text.
     */
    private function rawCityText(Order $order): ?string
    {
        if ($order->shipping_city_id !== null && $order->shippingCity !== null) {
            return $order->shippingCity->name;
        }

        return OrderAddressSummary::extract($order)['city'];
    }

    private function mappingForCityId(string $storeId, string $providerCode, string $cityId): ?CityDeliveryProviderMapping
    {
        return CityDeliveryProviderMapping::query()
            ->where('store_id', $storeId)
            ->where('city_id', $cityId)
            ->where('provider_code', $providerCode)
            ->with('providerCity')
            ->first();
    }

    private function findInternalCityByNormalizedName(string $normalized): ?City
    {
        return City::query()
            ->where('country_code', 'MA')
            ->where('is_active', true)
            ->get()
            ->first(fn (City $city) => CityNameNormalizer::normalize($city->name) === $normalized);
    }

    /** @param array<int, string> $normalizedCandidates */
    private function findProviderCityByAnyNormalizedName(string $storeId, string $providerCode, array $normalizedCandidates): ?DeliveryProviderCity
    {
        return DeliveryProviderCity::query()
            ->where('store_id', $storeId)
            ->where('provider_code', $providerCode)
            ->get()
            ->first(fn (DeliveryProviderCity $pc) => in_array(CityNameNormalizer::normalize($pc->city_name), $normalizedCandidates, true));
    }

    /** @return array{resolved: true, internal_city_id: ?string, internal_city_name: ?string, provider_city_id: string, provider_city_name: string, provider_city_record_id: string, resolution_source: string, raw_city_text: ?string, normalized_city_text: ?string, suggested_internal_city_id: null, suggested_internal_city_name: null, error: null} */
    private function resolved(string $source, ?City $internalCity, DeliveryProviderCity $providerCity, ?string $rawCity, ?string $normalized): array
    {
        return [
            'resolved' => true,
            'internal_city_id' => $internalCity?->id,
            'internal_city_name' => $internalCity?->name,
            'provider_city_id' => $providerCity->provider_city_id,
            'provider_city_name' => $providerCity->city_name,
            'provider_city_record_id' => $providerCity->id,
            'resolution_source' => $source,
            'raw_city_text' => $rawCity,
            'normalized_city_text' => $normalized,
            'suggested_internal_city_id' => null,
            'suggested_internal_city_name' => null,
            'error' => null,
        ];
    }

    private function buildError(?string $rawCity, ?City $internalCity, ?City $suggested): string
    {
        if ($rawCity === null) {
            return 'This order has no city information on file — set a shipping city before sending it to Ozon.';
        }

        if ($internalCity !== null) {
            return "City '{$rawCity}' matched internal city '{$internalCity->name}', but no Ozon mapping was found for it. Map it on the Delivery providers page.";
        }

        return $suggested !== null
            ? "City '{$rawCity}' is not mapped to an Ozon city. Suggested internal match: {$suggested->name}."
            : "City '{$rawCity}' is not mapped to an Ozon city. Please map it first.";
    }

    /** A hint only — never used to auto-resolve, only surfaced in the error message/UI. */
    private function suggestInternalCity(string $normalized): ?City
    {
        $best = null;
        $bestScore = 0.0;

        foreach (City::query()->where('country_code', 'MA')->where('is_active', true)->get() as $city) {
            $score = CityNameNormalizer::similarity($normalized, CityNameNormalizer::normalize($city->name));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $city;
            }
        }

        return $bestScore >= self::SUGGESTION_THRESHOLD ? $best : null;
    }
}
