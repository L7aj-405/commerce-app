<?php

declare(strict_types=1);

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\DeliveryCityMappingSuggestionService;

/*
|--------------------------------------------------------------------------
| Conservative city-matching: exact/alias/fuzzy suggestions, and never
| auto-mapping anything ambiguous or low-confidence.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    // Every assertion here calls the suggestion service directly (no HTTP
    // request), but DeliveryProviderCity/CityDeliveryProviderMapping are
    // still tenant-scoped — actingAs() is what resolves getActiveStore() for
    // that scope, exactly like the direct-service tests in OzonCityMappingTest.
    $this->actingAs($this->owner);

    $this->service = app(DeliveryCityMappingSuggestionService::class);
});

function suggestionFor(Store $store, City $city, DeliveryCityMappingSuggestionService $service): ?array
{
    return $service->suggestionsFor($store)->firstWhere('internal_city_id', $city->id);
}

function ozonCity(Store $store, string $providerCityId, string $name): DeliveryProviderCity
{
    return DeliveryProviderCity::create([
        'store_id' => $store->id, 'provider_code' => 'ozon',
        'provider_city_id' => $providerCityId, 'city_name' => $name,
    ]);
}

it('returns "none" with a clear reason when no Ozon cities are synced yet', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('none')
        ->and($suggestion['can_auto_map'])->toBeFalse()
        ->and($suggestion['suggested_provider_city_id'])->toBeNull()
        ->and($suggestion['reason'])->toContain('No Ozon cities synced');
});

it('suggests an exact match', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $ozon = ozonCity($this->store, '1', 'Casablanca');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('exact')
        ->and($suggestion['can_auto_map'])->toBeTrue()
        ->and($suggestion['confidence'])->toBe(100.0)
        ->and($suggestion['suggested_provider_city_id'])->toBe($ozon->id)
        ->and($suggestion['suggested_provider_city_name'])->toBe('Casablanca');
});

it('is accent-insensitive: Béni Mellal matches Beni Mellal exactly', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'BMA', 'name' => 'Béni Mellal', 'is_active' => true]);
    ozonCity($this->store, '2', 'Beni Mellal');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('exact')
        ->and($suggestion['can_auto_map'])->toBeTrue()
        ->and($suggestion['suggested_provider_city_name'])->toBe('Beni Mellal');
});

it('is accent-insensitive: Al Hoceïma matches Al Hoceima exactly', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'ALH', 'name' => 'Al Hoceïma', 'is_active' => true]);
    ozonCity($this->store, '3', 'Al Hoceima');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('exact')
        ->and($suggestion['can_auto_map'])->toBeTrue();
});

it('matches via the alias dictionary when the spelling is a genuinely different word', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $ozon = ozonCity($this->store, '4', 'Casa');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('alias')
        ->and($suggestion['can_auto_map'])->toBeTrue()
        ->and($suggestion['suggested_provider_city_id'])->toBe($ozon->id);
});

it('marks a genuinely ambiguous fuzzy match as needing review and does not auto-map it', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'TST', 'name' => 'Abcdefghij', 'is_active' => true]);
    // Two Ozon cities that each differ from the internal name by exactly one
    // trailing character — symmetric edits score identically (or within a
    // point) via similar_text(), so there is no safe way to pick automatically.
    ozonCity($this->store, '5', 'Abcdefghik');
    ozonCity($this->store, '6', 'Abcdefghil');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('ambiguous')
        ->and($suggestion['can_auto_map'])->toBeFalse()
        ->and($suggestion['reason'])->not->toBeEmpty();
});

it('marks a city with no similar Ozon city as no_match', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'ZZZ', 'name' => 'Zzznotarealcityname', 'is_active' => true]);
    ozonCity($this->store, '7', 'Casablanca');
    ozonCity($this->store, '8', 'Rabat');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('none')
        ->and($suggestion['can_auto_map'])->toBeFalse();
});

it('marks a low-confidence fuzzy match as needing review rather than auto-mapping it', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'ABC', 'name' => 'Abcdefgh', 'is_active' => true]);
    // Shares only a few characters — similar_text() should land it in the
    // "some similarity but not enough to trust" band.
    ozonCity($this->store, '9', 'Abczzzzzzzzzzzzzzzzzzzzzzz');

    $suggestion = suggestionFor($this->store, $city, $this->service);

    if ($suggestion['match_type'] === 'fuzzy') {
        expect($suggestion['can_auto_map'])->toBeFalse();
    } else {
        expect($suggestion['match_type'])->toBe('none');
    }
});

it('excludes already-mapped cities from suggestions by default', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $ozon = ozonCity($this->store, '1', 'Casablanca');
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $city->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id,
    ]);

    expect(suggestionFor($this->store, $city, $this->service))->toBeNull();
});

it('includes already-mapped cities when includeMapped is requested', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $ozon = ozonCity($this->store, '1', 'Casablanca');
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $city->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id,
    ]);

    $suggestion = $this->service->suggestionsFor($this->store, 'ozon', includeMapped: true)
        ->firstWhere('internal_city_id', $city->id);

    expect($suggestion)->not->toBeNull()
        ->and($suggestion['match_type'])->toBe('exact');
});

it('never mixes suggestions across stores', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $otherStore = Store::factory()->create(['user_id' => $this->owner->id]);
    ozonCity($otherStore, '1', 'Casablanca');

    // Nothing synced for $this->store, so it must not see the other store's city.
    $suggestion = suggestionFor($this->store, $city, $this->service);

    expect($suggestion['match_type'])->toBe('none');
});
