<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Sync cities: fetch, parse (every documented response shape), upsert,
| and surface real counts/errors to the UI — not a generic "Done."
|--------------------------------------------------------------------------
*/

function ozonManager(Store $store): User
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
    $this->manager = ozonManager($this->store);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'super-secret-key'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
});

it('calls the plain top-level /cities endpoint via GET, not the customer-scoped path', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => [
        ['CITY_ID' => '17', 'CITY_NAME' => 'Casablanca'],
    ]], 200)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk();

    Http::assertSent(function ($request) {
        expect($request->method())->toBe('GET')
            ->and($request->url())->toBe('https://api.ozonexpress.ma/cities')
            ->and($request->url())->not->toContain('CUST123')
            ->and($request->url())->not->toContain('super-secret-key');

        return true;
    });
});

it('upserts cities and returns imported/updated/total counts as JSON', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => [
        ['CITY_ID' => '17', 'CITY_NAME' => 'Casablanca'],
        ['CITY_ID' => '5', 'CITY_NAME' => 'Rabat'],
    ]], 200)]);

    $response = $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk()
        ->assertJson(['ok' => true, 'imported_count' => 2, 'updated_count' => 0, 'total_count' => 2]);

    expect($response->json('message'))->toContain('2')
        ->and(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_code', 'ozon')->count())->toBe(2)
        ->and(DeliveryProviderCity::where('provider_city_id', '17')->first())
            ->city_name->toBe('Casablanca')
            ->raw_payload->toBe(['CITY_ID' => '17', 'CITY_NAME' => 'Casablanca']);
});

dataset('ozon_city_response_shapes', [
    'plain list, lowercase id/name' => [['id' => 1, 'name' => 'Casablanca']],
    'plain list, uppercase ID/NAME' => [['ID' => 1, 'NAME' => 'Casablanca']],
    'plain list, CITY_ID/CITY_NAME' => [['CITY_ID' => 1, 'CITY_NAME' => 'Casablanca']],
]);

it('parses every documented city shape inside a bare list', function (array $row) {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response([$row], 200)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk()
        ->assertJson(['ok' => true, 'total_count' => 1]);

    expect(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '1')->value('city_name'))->toBe('Casablanca');
})->with('ozon_city_response_shapes');

it('parses the REAL Ozon /cities response: an uppercase CITIES object keyed by city id', function () {
    // Exact shape confirmed from https://api.ozonexpress.ma/cities.
    Http::fake(['api.ozonexpress.ma/cities' => Http::response([
        'CITIES' => [
            '37' => ['ID' => 37, 'REF' => 'AGA', 'NAME' => 'Agadir', 'DELIVERED-PRICE' => 35, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 10],
            '49' => ['ID' => 49, 'REF' => 'AIL', 'NAME' => 'Ait Melloul', 'DELIVERED-PRICE' => 35, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 10],
        ],
    ], 200)]);

    $response = $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk()
        ->assertJson(['ok' => true, 'imported_count' => 2, 'total_count' => 2]);

    $agadir = DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '37')->first();

    expect($agadir)->not->toBeNull()
        ->and($agadir->city_name)->toBe('Agadir')
        ->and($agadir->city_ref)->toBe('AGA')
        ->and((float) $agadir->delivered_price)->toBe(35.0)
        ->and((float) $agadir->returned_price)->toBe(0.0)
        ->and((float) $agadir->refused_price)->toBe(10.0)
        ->and($agadir->raw_payload)->toBe(['ID' => 37, 'REF' => 'AGA', 'NAME' => 'Agadir', 'DELIVERED-PRICE' => 35, 'RETURNED-PRICE' => 0, 'REFUSED-PRICE' => 10]);

    expect($this->connection->fresh())
        ->last_city_sync_count->toBe(2)
        ->last_city_sync_error->toBeNull()
        ->status->toBe(DeliveryConnection::STATUS_CONNECTED);
});

it('upserts cities without creating duplicates on a re-sync of the CITIES shape', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response([
        'CITIES' => ['37' => ['ID' => 37, 'REF' => 'AGA', 'NAME' => 'Agadir']],
    ], 200)]);

    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities");
    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertJson(['ok' => true, 'imported_count' => 0, 'updated_count' => 1, 'total_count' => 1]);

    expect(DeliveryProviderCity::where('store_id', $this->store->id)->where('provider_city_id', '37')->count())->toBe(1);
});

it('parses cities wrapped in a "data" key', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['data' => [
        ['id' => 1, 'name' => 'Casablanca'],
        ['id' => 2, 'name' => 'Rabat'],
    ]], 200)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk()
        ->assertJson(['ok' => true, 'total_count' => 2]);
});

it('parses cities wrapped in a "cities" key', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => [
        ['id' => 1, 'name' => 'Casablanca'],
    ]], 200)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk()
        ->assertJson(['ok' => true, 'total_count' => 1]);
});

it('shows the exact required message and sets last_city_sync_error — never last_error, never disables the connection', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => []], 200)]);

    $response = $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'message' => 'City sync failed: no Ozon cities were imported.']);

    $fresh = $this->connection->fresh();

    expect($fresh->last_city_sync_error)->toBe('City sync failed: no Ozon cities were imported.')
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->status)->toBe(DeliveryConnection::STATUS_CONNECTED)
        ->and(DeliveryProviderCity::count())->toBe(0);
});

it('shows the same zero-cities message when the response shape is unrecognized, and still leaves status alone', function () {
    // Neither a bare list nor data/cities-wrapped — nothing usable to parse.
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['unexpected' => 'shape'], 200)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'message' => 'City sync failed: no Ozon cities were imported.']);

    expect($this->connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});

it('sets last_city_sync_error (not last_error) and keeps status connected when the Ozon endpoint fails', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response('Service unavailable', 503)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    $fresh = $this->connection->fresh();

    expect($fresh->last_city_sync_error)->toContain('503')
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});

it('clears a stale last_city_sync_error and records last_city_sync_at/count on a subsequent successful sync', function () {
    $this->connection->update(['last_city_sync_error' => 'Previous failure']);

    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => [
        ['CITY_ID' => '17', 'CITY_NAME' => 'Casablanca'],
    ]], 200)]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertOk();

    $fresh = $this->connection->fresh();

    expect($fresh->last_city_sync_error)->toBeNull()
        ->and($fresh->last_city_sync_at)->not->toBeNull()
        ->and($fresh->last_city_sync_count)->toBe(1)
        ->and($fresh->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});

it('never lets a city-sync failure disable an authenticated connection, even repeatedly', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => []], 200)]);

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($this->manager)
            ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
            ->assertStatus(422);
    }

    expect($this->connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});

it('never logs the api_key when the sync request throws', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
    });
    Log::spy();

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertStatus(422);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        $flat = $message . json_encode($context);

        expect($flat)->not->toContain('super-secret-key');

        return true;
    });
});

it('does not let another store sync or see this connection\'s cities', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();
    $otherManager = ozonManager($otherStore);

    $this->actingAs($otherManager)
        ->postJson("/dashboard/delivery-connections/{$this->connection->id}/sync-cities")
        ->assertNotFound();
});
