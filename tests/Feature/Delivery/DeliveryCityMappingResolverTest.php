<?php

declare(strict_types=1);

use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\DeliveryCityMappingResolver;

/*
|--------------------------------------------------------------------------
| DeliveryCityMappingResolver — resolving which Ozon city a packed order
| ships to, without requiring the order to have a resolved shipping_city_id.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    // Direct-service tests still need tenant scope resolved.
    $this->actingAs($this->owner);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->resolver = app(DeliveryCityMappingResolver::class);
});

function makeUnroutedOrder(Store $store, array $overrides = []): Order
{
    return Order::factory()->create(array_merge([
        'store_id' => $store->id,
        'shipping_city_id' => null,
        'source_platform' => 'shopify',
        'platform_data' => [],
    ], $overrides));
}

it('resolves via confirmed_city_id when the order already has a mapped shipping_city_id', function () {
    $city = City::where('country_code', 'MA')->where('is_active', true)->firstOrFail();
    $ozon = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '99', 'city_name' => $city->name]);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id]);

    $order = makeUnroutedOrder($this->store, ['shipping_city_id' => $city->id]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['resolution_source'])->toBe('confirmed_city_id')
        ->and($resolution['provider_city_id'])->toBe('99')
        ->and($resolution['internal_city_name'])->toBe($city->name);
});

it('resolves the seeded "Béni Mellal" from raw platform text "beni mellal" (accent/case-insensitive)', function () {
    $beniMellal = City::where('country_code', 'MA')->where('name', 'Béni Mellal')->firstOrFail();
    $ozon = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '10', 'city_name' => 'Beni Mellal']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $beniMellal->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id]);

    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'beni mellal']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['resolution_source'])->toBe('normalized_city_name')
        ->and($resolution['internal_city_name'])->toBe('Béni Mellal')
        ->and($resolution['provider_city_id'])->toBe('10')
        ->and($resolution['provider_city_name'])->toBe('Beni Mellal');
});

it('resolves the seeded "Al Hoceïma" from raw platform text "Al Hoceima"', function () {
    $alHoceima = City::where('country_code', 'MA')->where('name', 'Al Hoceïma')->firstOrFail();
    $ozon = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '11', 'city_name' => 'Al Hoceima']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $alHoceima->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id]);

    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'Al Hoceima']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['internal_city_name'])->toBe('Al Hoceïma');
});

it('resolves via the alias dictionary when the raw text is a genuinely different word (Casa -> Casablanca)', function () {
    $casablanca = City::where('country_code', 'MA')->where('name', 'Casablanca')->firstOrFail();
    $ozon = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '1', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $casablanca->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $ozon->id]);

    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'Casa']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['resolution_source'])->toBe('alias')
        ->and($resolution['internal_city_name'])->toBe('Casablanca');
});

it('resolves directly to a synced Ozon city by name when no internal mapping exists at all', function () {
    DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '55', 'city_name' => 'Zzuniqueprovidercity']);

    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'Zzuniqueprovidercity']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeTrue()
        ->and($resolution['resolution_source'])->toBe('direct_provider_city')
        ->and($resolution['provider_city_id'])->toBe('55');
});

it('returns a clear error naming the matched internal city when it has no Ozon mapping yet', function () {
    // "Béni Mellal" is a real internal city (seeded), but never mapped here.
    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'beni mellal']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeFalse()
        ->and($resolution['internal_city_name'])->toBe('Béni Mellal')
        ->and($resolution['error'])->toContain('beni mellal')
        ->and($resolution['error'])->toContain('Béni Mellal')
        ->and($resolution['error'])->toContain('no Ozon mapping');
});

it('returns a clear error with a suggested match for a truly unmapped/unrecognized city', function () {
    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'Zzznotarealcityname']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeFalse()
        ->and($resolution['resolution_source'])->toBe('unmapped')
        ->and($resolution['error'])->toContain('Zzznotarealcityname');
});

it('never resolves using another store\'s mapping', function () {
    $beniMellal = City::where('country_code', 'MA')->where('name', 'Béni Mellal')->firstOrFail();

    $otherStore = Store::factory()->create(['user_id' => $this->owner->id]);
    $otherOzon = DeliveryProviderCity::create(['store_id' => $otherStore->id, 'provider_code' => 'ozon', 'provider_city_id' => '10', 'city_name' => 'Beni Mellal']);
    CityDeliveryProviderMapping::create(['store_id' => $otherStore->id, 'city_id' => $beniMellal->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $otherOzon->id]);

    $order = makeUnroutedOrder($this->store, [
        'platform_data' => ['shipping_address' => ['city' => 'beni mellal']],
    ]);

    $resolution = $this->resolver->resolve($order, $this->connection);

    expect($resolution['resolved'])->toBeFalse();
});
