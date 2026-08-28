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
| "Map all suggested" — bulk-applies safe suggestions only.
|--------------------------------------------------------------------------
*/

function bulkTestManager(Store $store): User
{
    $role = $store->roles()->where('name', 'Manager')->firstOrFail();
    $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $member;
}

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();
    $this->manager = bulkTestManager($this->store);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
});

it('maps only can_auto_map=true rows and reports mapped/skipped counts', function () {
    // Exact match — safe.
    $casa = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Casablanca']);

    // No candidate at all — must be skipped.
    $noMatch = City::create(['country_code' => 'MA', 'code' => 'ZZZ', 'name' => 'Zzznotarealcityname', 'is_active' => true]);

    $response = $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/cities/map-all-suggested")
        ->assertOk();

    expect($response->json('mapped_count'))->toBeGreaterThanOrEqual(1)
        ->and($response->json('skipped_count'))->toBeGreaterThanOrEqual(1)
        ->and($response->json('message'))->toContain('Mapped')->toContain('skipped');

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $casa->id)->exists())->toBeTrue()
        ->and(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $noMatch->id)->exists())->toBeFalse();
});

it('skips ambiguous rows and lists a reason for each skip', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'TST', 'name' => 'Abcdefghij', 'is_active' => true]);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Abcdefghik']);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '2', 'city_name' => 'Abcdefghil']);

    $response = $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/cities/map-all-suggested")
        ->assertOk();

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $city->id)->exists())->toBeFalse()
        ->and($response->json('skipped_reasons'))->not->toBeEmpty();
});

it('does not create duplicate mappings when run twice', function () {
    // A fresh, unique name so this test's count isn't muddied by the ~34
    // cities the inventory-engine migration already seeds (which include a
    // real "Casablanca" that would ALSO get auto-mapped and inflate counts).
    $city = City::create(['country_code' => 'MA', 'code' => 'ZZTEST', 'name' => 'Zzuniquetestcity', 'is_active' => true]);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Zzuniquetestcity']);

    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$this->connection->id}/cities/map-all-suggested");
    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$this->connection->id}/cities/map-all-suggested");

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $city->id)->count())->toBe(1);
});

it('does not let another store trigger a map-all on this connection', function () {
    City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Casablanca']);

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();
    $otherManager = bulkTestManager($otherStore);

    $this->actingAs($otherManager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/cities/map-all-suggested")
        ->assertNotFound();

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->count())->toBe(0);
});

it('a plain picker without delivery.connections.manage cannot bulk-map', function () {
    $role = $this->store->roles()->where('name', 'Warehouse')->firstOrFail();
    $picker = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $picker->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($picker)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/cities/map-all-suggested")
        ->assertForbidden();
});

it('clears an existing mapping', function () {
    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $ozon = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create([
        'store_id' => $this->store->id, 'city_id' => $city->id,
        'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id,
    ]);

    $this->actingAs($this->manager)
        ->post("/dashboard/delivery-connections/{$this->connection->id}/cities/clear-mapping", ['city_id' => $city->id])
        ->assertRedirect();

    expect(CityDeliveryProviderMapping::where('store_id', $this->store->id)->where('city_id', $city->id)->exists())->toBeFalse();
});
