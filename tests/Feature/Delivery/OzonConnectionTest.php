<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Ozon Express connection: credentials, masking, tenant isolation
|--------------------------------------------------------------------------
*/

function makeManager(Store $store): User
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
    $this->manager = makeManager($this->store);
});

it('stores Ozon credentials encrypted at rest', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express',
        'customer_id' => 'CUST123',
        'api_key' => 'super-secret-key',
    ])->assertRedirect();

    $connection = DeliveryConnection::where('store_id', $this->store->id)->where('provider_code', 'ozon')->firstOrFail();

    $raw = DB::table('delivery_connections')->where('id', $connection->id)->value('credentials');

    expect($raw)->not->toContain('super-secret-key')
        ->and($connection->credential('api_key'))->toBe('super-secret-key');
});

it('never returns the api_key in the Inertia/JSON response shape', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express',
        'customer_id' => 'CUST123',
        'api_key' => 'super-secret-key',
    ]);

    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();
    $shape = $connection->toApiArray();

    expect($shape)->not->toHaveKey('api_key')
        ->and($shape)->not->toHaveKey('credentials')
        ->and($shape['has_credentials'])->toBeTrue();

    expect(json_encode($shape))->not->toContain('super-secret-key');
});

it('tests the connection via Http::fake and updates status without leaking the api_key to logs', function () {
    // testConnection() hits Ozon's plain top-level /cities route (not nested
    // under /customers/{id}/{key}/), so the request URL never carries the
    // credentials at all — nothing to leak.
    Http::fake(['api.ozonexpress.ma/cities' => Http::response(['cities' => []], 200)]);
    Log::spy();

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express', 'customer_id' => 'CUST123', 'api_key' => 'super-secret-key',
    ]);
    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->post("/dashboard/delivery-connections/{$connection->id}/test")
        ->assertRedirect();

    expect($connection->refresh()->status)->toBe(DeliveryConnection::STATUS_CONNECTED);

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://api.ozonexpress.ma/cities')
            ->not->toContain('CUST123')
            ->not->toContain('super-secret-key');

        return true;
    });

    // The happy path never logs at all — logFailure() only fires on error.
    Log::shouldNotHaveReceived('warning');
});

it('masks the api_key in the log line when the request to Ozon throws', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
    });
    Log::spy();

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express', 'customer_id' => 'CUST123', 'api_key' => 'super-secret-key',
    ]);
    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();

    $this->actingAs($this->manager)->post("/dashboard/delivery-connections/{$connection->id}/test");

    expect($connection->refresh()->status)->toBe(DeliveryConnection::STATUS_ERROR);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        $flat = $message . json_encode($context);

        // /cities carries no credentials at all (it's the unscoped top-level
        // route), so there is nothing to mask — just confirm the secret
        // never appears anywhere in the log line.
        expect($flat)->not->toContain('super-secret-key')
            ->and($context['context'] ?? '')->toBe('test-connection');

        return true;
    });
});

it('rejects a store trying to test another store\'s connection', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express', 'customer_id' => 'CUST123', 'api_key' => 'super-secret-key',
    ]);
    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();
    $otherManager = makeManager($otherStore);

    $this->actingAs($otherManager)
        ->post("/dashboard/delivery-connections/{$connection->id}/test")
        ->assertNotFound();
});

it('blocks a plain picker from managing delivery connections', function () {
    $role = $this->store->roles()->where('name', 'Warehouse')->firstOrFail();
    $picker = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $picker->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($picker)->post('/dashboard/delivery-connections/ozon', [
        'name' => 'Ozon Express', 'customer_id' => 'CUST123', 'api_key' => 'x',
    ])->assertForbidden();
});
