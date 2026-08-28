<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\SenditDistrictMappingService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sendit district sync — GET /districts, stored into the SAME
| delivery_provider_cities table Ozon's city sync uses (provider_code
| distinguishes them). Every fake URL below uses a trailing "*" wildcard —
| listDistricts() now always sends ?page=&pickup-district= query params, so
| a bare (non-wildcard) URL pattern would silently NOT match.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
});

function senditLoginFake(): array
{
    return ['app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_sync'], 200)];
}

/** A single-page (no pagination metadata at all) districts response — the simplest, still-valid shape. */
function senditSinglePageDistrictsResponse(array $rows): mixed
{
    return Http::response(['data' => $rows], 200);
}

it('syncs Sendit districts and stores their provider ids/names/price/delais/pickup flag', function () {
    Http::fake([
        ...senditLoginFake(),
        'app.sendit.ma/api/v1/districts?*' => senditSinglePageDistrictsResponse([
            ['id' => 12, 'ville' => 'Casablanca', 'name' => 'Casablanca', 'price' => 25, 'delais' => '24h', 'pickup_district' => true],
            ['id' => 7, 'ville' => 'Rabat', 'name' => 'Rabat', 'price' => 30, 'delais' => '48h', 'pickup_district' => false],
        ]),
    ]);

    $counts = app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    expect($counts['imported_count'])->toBe(2)
        ->and($counts['updated_count'])->toBe(0)
        ->and($counts['total_count'])->toBe(2)
        ->and($counts['distinct_cities_count'])->toBe(2)
        ->and($counts['pages_fetched'])->toBe(1)
        ->and($counts['pickup_district_used'])->toBe('46'); // Sendit's documented default, no connection setting configured

    $casa = DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '12')->firstOrFail();
    expect($casa->city_name)->toBe('Casablanca')
        ->and((float) $casa->price)->toBe(25.0)
        ->and($casa->delais)->toBe('24h')
        ->and($casa->is_pickup_district)->toBeTrue()
        // ville === name here, so district_name is correctly left null
        // rather than duplicating "Casablanca" into both fields.
        ->and($casa->district_name)->toBeNull();

    $rabat = DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '7')->firstOrFail();
    expect($rabat->is_pickup_district)->toBeFalse();
});

it('keeps ville and the district name as two separate stored fields when they differ', function () {
    Http::fake([
        ...senditLoginFake(),
        'app.sendit.ma/api/v1/districts?*' => senditSinglePageDistrictsResponse([
            ['id' => 30, 'ville' => 'Marrakech', 'name' => 'Marrakech Menara', 'name_arabic' => 'مراكش', 'price' => 28],
        ]),
    ]);

    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    $district = DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '30')->firstOrFail();
    expect($district->city_name)->toBe('Marrakech')
        ->and($district->district_name)->toBe('Marrakech Menara')
        ->and($district->name_arabic)->toBe('مراكش')
        ->and($district->raw_payload['name'])->toBe('Marrakech Menara');
});

it('reports updates separately from new imports on a re-sync', function () {
    DeliveryProviderCity::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casa (old)',
    ]);

    Http::fake([
        ...senditLoginFake(),
        'app.sendit.ma/api/v1/districts?*' => senditSinglePageDistrictsResponse([
            ['id' => 12, 'ville' => 'Casablanca', 'price' => 25],
            ['id' => 7, 'ville' => 'Rabat', 'price' => 30],
        ]),
    ]);

    $counts = app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    expect($counts['imported_count'])->toBe(1)
        ->and($counts['updated_count'])->toBe(1)
        ->and($counts['total_count'])->toBe(2)
        ->and(DeliveryProviderCity::where('provider_city_id', '12')->value('city_name'))->toBe('Casablanca');
});

it('never duplicates rows on a repeated sync', function () {
    Http::fake([
        ...senditLoginFake(),
        'app.sendit.ma/api/v1/districts?*' => senditSinglePageDistrictsResponse([
            ['id' => 12, 'ville' => 'Casablanca', 'price' => 25],
            ['id' => 7, 'ville' => 'Rabat', 'price' => 30],
        ]),
    ]);

    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);
    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);
    app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    expect(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_code', 'sendit')->count())->toBe(2);
});

it('fails clearly when Sendit login fails during a district sync', function () {
    Http::fake(['app.sendit.ma/api/v1/login' => Http::response(['message' => 'bad creds'], 401)]);

    expect(fn () => app(SenditDistrictMappingService::class)->syncDistricts($this->connection))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('syncs districts over HTTP via the dashboard endpoint and reports rich counts in the message', function () {
    Http::fake([
        ...senditLoginFake(),
        'app.sendit.ma/api/v1/districts?*' => senditSinglePageDistrictsResponse([
            ['id' => 12, 'ville' => 'Casablanca', 'price' => 25],
        ]),
    ]);

    $response = $this->actingAs($this->owner)
        ->postJson('/dashboard/delivery-connections/sendit/sync-districts')
        ->assertOk()
        ->assertJson(['ok' => true, 'imported_count' => 1, 'distinct_cities_count' => 1, 'pages_fetched' => 1]);

    expect($response->json('message'))
        ->toContain('Synced 1 Sendit districts')
        ->toContain('1 cities found')
        ->toContain('Pickup district used');

    expect(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_code', 'sendit')->count())->toBe(1);

    $this->connection->refresh();
    expect($this->connection->last_city_sync_page_count)->toBe(1)
        ->and($this->connection->last_city_sync_pickup_district_id)->toBe('46');
});

it('uses the connection\'s configured default pickup district over the Sendit fallback', function () {
    $this->connection->update(['settings' => ['default_pickup_district_id' => '99']]);

    Http::fake([
        ...senditLoginFake(),
        'app.sendit.ma/api/v1/districts?*' => senditSinglePageDistrictsResponse([
            ['id' => 12, 'ville' => 'Casablanca', 'price' => 25],
        ]),
    ]);

    $counts = app(SenditDistrictMappingService::class)->syncDistricts($this->connection);

    expect($counts['pickup_district_used'])->toBe('99');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/districts')) {
            return false;
        }

        return str_contains($request->url(), 'pickup-district=99');
    });
});
