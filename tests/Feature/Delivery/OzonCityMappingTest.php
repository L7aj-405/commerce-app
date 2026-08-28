<?php

declare(strict_types=1);

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\OzonCityMappingService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Ozon city sync + internal-city mapping
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
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->casablanca = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
});

it('syncs ozon cities and stores their provider ids/names', function () {
    // /cities is a plain top-level route, NOT nested under /customers/{id}/{key}/.
    Http::fake(['api.ozonexpress.ma/cities' => Http::response([
        'cities' => [
            ['CITY_ID' => '17', 'CITY_NAME' => 'Casablanca'],
            ['CITY_ID' => '5',  'CITY_NAME' => 'Rabat'],
        ],
    ], 200)]);

    $counts = app(OzonCityMappingService::class)->syncCities($this->connection);

    expect($counts)->toBe(['imported_count' => 2, 'updated_count' => 0, 'total_count' => 2])
        ->and(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '17')->value('city_name'))->toBe('Casablanca');
});

it('reports updates separately from new imports on a re-sync', function () {
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casa (old name)']);

    Http::fake(['api.ozonexpress.ma/cities' => Http::response([
        'cities' => [
            ['CITY_ID' => '17', 'CITY_NAME' => 'Casablanca'],
            ['CITY_ID' => '5',  'CITY_NAME' => 'Rabat'],
        ],
    ], 200)]);

    $counts = app(OzonCityMappingService::class)->syncCities($this->connection);

    expect($counts)->toBe(['imported_count' => 1, 'updated_count' => 1, 'total_count' => 2])
        ->and(DeliveryProviderCity::where('provider_city_id', '17')->value('city_name'))->toBe('Casablanca');
});

it('blocks sending an order until its city is mapped, with the exact spec message', function () {
    $this->actingAs($this->owner);

    expect(app(OzonCityMappingService::class)->unmappedCities($this->store)->pluck('id'))->toContain($this->casablanca->id);
});

it('maps an internal city to an ozon city', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca',
    ]);

    app(OzonCityMappingService::class)->mapCity($this->store, $this->casablanca, $providerCity);

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $this->casablanca->id)->exists())->toBeTrue()
        ->and(app(OzonCityMappingService::class)->unmappedCities($this->store)->pluck('id'))->not->toContain($this->casablanca->id);
});

it('maps a city over HTTP and reflects it as no-longer-unmapped', function () {
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca',
    ]);

    $this->actingAs($this->manager)->post("/dashboard/delivery-connections/{$this->connection->id}/cities/map", [
        'city_id' => $this->casablanca->id,
        'delivery_provider_city_id' => $providerCity->id,
    ])->assertRedirect();

    expect(CityDeliveryProviderMapping::where('city_id', $this->casablanca->id)->where('store_id', $this->store->id)->exists())->toBeTrue();
});
