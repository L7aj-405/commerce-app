<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| DeliveryConnection.status is authentication ONLY: connected|error come
| exclusively from test(), disabled comes exclusively from disconnect() (or
| the untested-default on first creation). Nothing else — not a settings
| save, not a city sync failure — may change it.
|--------------------------------------------------------------------------
*/

function statusTestManager(Store $store): User
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
    $this->manager = statusTestManager($this->store);
});

it('defaults a brand-new connection to disabled (untested)', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express', 'customer_id' => 'CUST123', 'api_key' => 'secret',
    ]);

    expect(DeliveryConnection::where('store_id', $this->store->id)->firstOrFail()->status)
        ->toBe(DeliveryConnection::STATUS_DISABLED);
});

it('test connection success sets status connected and clears the auth error', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => []], 200)]);

    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_ERROR, 'last_error' => 'Previous auth failure',
    ]);

    $this->actingAs($this->manager)
        ->post("/dashboard/delivery-connections/{$connection->id}/test")
        ->assertRedirect();

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(DeliveryConnection::STATUS_CONNECTED)
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->last_tested_at)->not->toBeNull();
});

it('test connection failure sets status error and records the auth error, without touching city sync fields', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response('nope', 500)]);

    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
        'last_city_sync_count' => 12, 'last_city_sync_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->post("/dashboard/delivery-connections/{$connection->id}/test")
        ->assertRedirect();

    $fresh = $connection->fresh();

    expect($fresh->status)->toBe(DeliveryConnection::STATUS_ERROR)
        ->and($fresh->last_error)->not->toBeNull()
        ->and($fresh->last_city_sync_count)->toBe(12); // untouched
});

it('saving credentials/settings again does NOT reset an already-connected status to disabled', function () {
    // This is the exact regression: re-saving the form used to force
    // status back to 'disabled' unconditionally on every save.
    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED, 'last_tested_at' => now(),
    ]);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express (renamed)', 'customer_id' => 'CUST123', 'api_key' => 'secret',
        'default_note' => 'updated note',
    ]);

    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED)
        ->and($connection->fresh()->name)->toBe('Ozon Express (renamed)');
});

it('a sync-cities failure never sets status to disabled', function () {
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => []], 200)]);

    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$connection->id}/sync-cities")
        ->assertStatus(422);

    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});

it('disconnect is the only action that sets status disabled on an existing connected connection', function () {
    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/dashboard/delivery-connections/{$connection->id}/disconnect")
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_DISABLED);
});

it('full lifecycle: connect, test, city-sync fails repeatedly, still connected, then explicit disconnect disables it', function () {
    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_DISABLED,
    ]);

    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => []], 200)]);
    $this->actingAs($this->manager)->post("/dashboard/delivery-connections/{$connection->id}/test");
    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);

    // /cities returning an empty list is used for BOTH testConnection() (any
    // 2xx = ok) and syncCities() (0 rows = failure) — connected here, then
    // sync-cities fails on the same empty payload.
    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$connection->id}/sync-cities");
    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$connection->id}/sync-cities");
    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);

    $this->actingAs($this->manager)->postJson("/dashboard/delivery-connections/{$connection->id}/disconnect");
    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_DISABLED);
});

it('does not let another store disconnect this connection', function () {
    $connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();
    $otherManager = statusTestManager($otherStore);

    $this->actingAs($otherManager)
        ->postJson("/dashboard/delivery-connections/{$connection->id}/disconnect")
        ->assertNotFound();

    expect($connection->fresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);
});
