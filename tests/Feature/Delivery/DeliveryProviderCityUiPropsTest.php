<?php

declare(strict_types=1);

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| /dashboard/delivery-connections Inertia props: ozon_cities must reflect
| what's actually been synced, so the city-mapping dropdown is never stale
| or silently empty.
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
});

it('includes a suggestion for an unmapped city once its matching ozon city is synced', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'ZZTEST', 'name' => 'Zzuniquetestcity', 'is_active' => true]);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Zzuniquetestcity']);

    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(function ($page) use ($city) {
            $page->has('suggestions');

            return $page;
        });

    // Fetch the suggestion for this specific city directly (the page also
    // carries suggestions for every other unmapped seeded city, so index-
    // based prop paths aren't reliable here).
    $suggestions = app(\App\Services\Delivery\DeliveryCityMappingSuggestionService::class)->suggestionsFor($this->store);
    $mine = $suggestions->firstWhere('internal_city_id', $city->id);

    expect($mine['match_type'])->toBe('exact')->and($mine['can_auto_map'])->toBeTrue();
});

it('drops a city from suggestions once it is mapped', function () {
    $this->actingAs($this->manager);

    $city = City::create(['country_code' => 'MA', 'code' => 'ZZTEST', 'name' => 'Zzuniquetestcity', 'is_active' => true]);
    $ozon = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Zzuniquetestcity']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id]);

    $suggestions = app(\App\Services\Delivery\DeliveryCityMappingSuggestionService::class)->suggestionsFor($this->store);

    expect($suggestions->firstWhere('internal_city_id', $city->id))->toBeNull();
});

it('lists synced ozon cities in the ozon_cities prop', function () {
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '5', 'city_name' => 'Rabat']);

    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('ozon_cities', 2)
            ->where('ozon_cities.0.city_name', 'Casablanca')
            ->where('ozon_cities.1.city_name', 'Rabat'));
});

it('returns an empty ozon_cities prop, not an error, before any sync has run', function () {
    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('ozon_cities', 0));
});

it('surfaces a stored last_error on the connection prop', function () {
    $this->connection->update(['last_error' => 'No Ozon cities were imported. Check the provider response.']);

    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.last_error', 'No Ozon cities were imported. Check the provider response.'));
});

it('exposes connection status and city-sync status as separate fields on the connection prop', function () {
    $this->connection->update([
        'status' => DeliveryConnection::STATUS_CONNECTED,
        'last_error' => null,
        'last_city_sync_error' => 'City sync failed: no Ozon cities were imported.',
        'last_city_sync_at' => null,
        // NOT NULL with a default of 0 — "never synced" is expressed via
        // last_city_sync_at being null, not via this being null.
        'last_city_sync_count' => 0,
    ]);

    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The connection stays "connected" (auth is fine) even though
            // the city sync failed — the two must never be conflated.
            ->where('connection.status', 'connected')
            ->where('connection.last_error', null)
            ->where('connection.last_city_sync_error', 'City sync failed: no Ozon cities were imported.')
            ->where('connection.last_city_sync_at', null));
});

it('reports a successful city sync count separately from the connection status', function () {
    $this->connection->update([
        'status' => DeliveryConnection::STATUS_CONNECTED,
        'last_city_sync_at' => now(),
        'last_city_sync_count' => 34,
        'last_city_sync_error' => null,
    ]);

    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.status', 'connected')
            ->where('connection.last_city_sync_count', 34)
            ->where('connection.last_city_sync_error', null));
});

it('never includes the api_key or raw credentials in the connection prop', function () {
    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.has_credentials', true)
            ->missing('connection.api_key')
            ->missing('connection.credentials'));
});

it('excludes an already-mapped city from unmapped_cities and lists it in mapped_cities', function () {
    // The MA reference list already ships ~34 seeded cities (see the
    // inventory-engine migration) — count the real baseline instead of
    // assuming this test's city is the only one, so the assertion stays
    // correct if that seed list ever changes.
    $totalActiveMaCities = City::query()->where('country_code', 'MA')->where('is_active', true)->count();
    $city = City::where('country_code', 'MA')->where('is_active', true)->firstOrFail();

    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $this->actingAs($this->manager)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('unmapped_cities', $totalActiveMaCities - 1)
            ->has('mapped_cities', 1)
            ->where('mapped_cities.0.city_name', $city->name)
            ->where('mapped_cities.0.provider_city_name', 'Casablanca'));
});

it('scopes ozon_cities to the active store only', function () {
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->get('/dashboard/delivery-connections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('ozon_cities', 0));
});
