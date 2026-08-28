<?php

declare(strict_types=1);

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\DeliveryCityMappingSuggestionService;
use App\Services\Delivery\SenditDistrictMappingService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sendit internal-city -> district mapping — the SAME
| city_delivery_provider_mappings table Ozon uses (provider_code='sendit').
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $role = $this->store->roles()->where('name', 'Manager')->firstOrFail();
    $this->manager = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $this->manager->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->casablanca = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
});

it('maps an internal city to a Sendit district id', function () {
    $district = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);

    app(SenditDistrictMappingService::class)->mapCity($this->store, $this->casablanca, $district);

    $mapping = CityDeliveryProviderMapping::where('store_id', $this->store->id)
        ->where('city_id', $this->casablanca->id)
        ->where('provider_code', 'sendit')
        ->firstOrFail();

    expect($mapping->delivery_provider_city_id)->toBe($district->id)
        ->and(app(SenditDistrictMappingService::class)->unmappedCities($this->store)->pluck('id'))->not->toContain($this->casablanca->id);
});

it('never confuses a Sendit mapping with an Ozon mapping for the same internal city', function () {
    $ozonCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca',
    ]);
    $senditDistrict = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);

    app(\App\Services\Delivery\OzonCityMappingService::class)->mapCity($this->store, $this->casablanca, $ozonCity);
    app(SenditDistrictMappingService::class)->mapCity($this->store, $this->casablanca, $senditDistrict);

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $this->casablanca->id)->count())->toBe(2)
        ->and(CityDeliveryProviderMapping::where('provider_code', 'ozon')->where('city_id', $this->casablanca->id)->value('delivery_provider_city_id'))->toBe($ozonCity->id)
        ->and(CityDeliveryProviderMapping::where('provider_code', 'sendit')->where('city_id', $this->casablanca->id)->value('delivery_provider_city_id'))->toBe($senditDistrict->id);
});

it('maps a district over HTTP and reflects it as no-longer-unmapped', function () {
    $district = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit/cities/map', [
        'city_id' => $this->casablanca->id,
        'delivery_provider_city_id' => $district->id,
    ])->assertRedirect();

    expect(CityDeliveryProviderMapping::where('city_id', $this->casablanca->id)->where('store_id', $this->store->id)->where('provider_code', 'sendit')->exists())->toBeTrue();
});

it('maps all safely-suggested districts and skips ambiguous ones', function () {
    // A throwaway, uniquely-named city — never "Casablanca" or any other
    // seeded MA city, which would double-count against the ~34 cities the
    // inventory-engine migration already seeds (see cerebrum.md).
    $uniqueCity = City::create(['country_code' => 'MA', 'code' => 'SNDX', 'name' => 'Senditville Unique', 'is_active' => true]);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '99', 'city_name' => 'Senditville Unique']);

    $this->actingAs($this->manager)
        ->postJson('/dashboard/delivery-connections/sendit/cities/map-all-suggested')
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(CityDeliveryProviderMapping::where('city_id', $uniqueCity->id)->where('provider_code', 'sendit')->exists())->toBeTrue();
});

it('suggests a mapping for a city that only synced on page 2 — suggestions use the complete dataset, not just page 1', function () {
    // "Marrakech" is a real seeded MA city (see the inventory-engine
    // migration) — used directly rather than a throwaway name so this test
    // also proves the exact failure this ticket reports is fixed.
    $marrakech = City::where('country_code', 'MA')->where('name', 'Marrakech')->firstOrFail();

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok'], 200),
        'app.sendit.ma/api/v1/districts?*' => function ($request) {
            preg_match('/page=(\d+)/', $request->url(), $m);
            $page = (int) ($m[1] ?? 1);

            $rows = $page === 1
                ? [['id' => 1, 'ville' => 'Casablanca', 'name' => 'Casablanca']]
                : [['id' => 2, 'ville' => 'Marrakech', 'name' => 'Marrakech']];

            $lastPage = 2;

            return Http::response([
                'data' => $rows, 'current_page' => $page, 'last_page' => $lastPage, 'total' => 2,
                'next_page_url' => $page < $lastPage ? "https://app.sendit.ma/api/v1/districts?page=" . ($page + 1) : null,
            ], 200);
        },
    ]);

    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    $suggestions = app(DeliveryCityMappingSuggestionService::class)->suggestionsFor($this->store, 'sendit');
    $marrakechSuggestion = $suggestions->firstWhere('internal_city_id', $marrakech->id);

    expect($marrakechSuggestion)->not->toBeNull()
        ->and($marrakechSuggestion['match_type'])->toBe('exact')
        ->and($marrakechSuggestion['can_auto_map'])->toBeTrue();
});

it('populates the default pickup district dropdown from GET /districts/pickup-cities, never from delivery districts', function () {
    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok'], 200),
        // Delivery districts: Casablanca is NOT flagged as a pickup point here.
        'app.sendit.ma/api/v1/districts?*' => Http::response(['data' => [
            ['id' => 46, 'ville' => 'Casablanca', 'name' => 'Casablanca', 'pickup_district' => false],
            ['id' => 12, 'ville' => 'Rabat', 'name' => 'Rabat', 'pickup_district' => false],
        ]], 200),
        // Pickup-cities: the dedicated endpoint — Casablanca IS a valid
        // pickup origin here, Rabat is not returned at all.
        'app.sendit.ma/api/v1/districts/pickup-cities*' => Http::response(['data' => [
            ['id' => 46, 'ville' => 'Casablanca', 'name' => 'Casablanca'],
        ]], 200),
    ]);

    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    $casablanca = DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '46')->firstOrFail();
    $rabat = DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '12')->firstOrFail();

    // pickup-cities overrides the (here, false) per-row flag from the
    // delivery-districts endpoint — it is the sole source of truth.
    expect($casablanca->is_pickup_district)->toBeTrue()
        ->and($rabat->is_pickup_district)->toBeFalse();

    $dropdown = app(SenditDistrictMappingService::class)->pickupDistricts($this->store);
    expect(collect($dropdown['districts'])->pluck('city_name')->all())->toBe(['Casablanca']);
});

it('clears a Sendit mapping over HTTP', function () {
    $district = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca',
    ]);
    app(SenditDistrictMappingService::class)->mapCity($this->store, $this->casablanca, $district);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit/cities/clear-mapping', [
        'city_id' => $this->casablanca->id,
    ])->assertRedirect();

    expect(CityDeliveryProviderMapping::where('city_id', $this->casablanca->id)->where('provider_code', 'sendit')->exists())->toBeFalse();
});
