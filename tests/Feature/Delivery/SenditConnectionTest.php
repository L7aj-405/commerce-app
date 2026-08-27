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
| Sendit connection: credentials, masking, tenant isolation
|--------------------------------------------------------------------------
*/

function senditManager(Store $store): User
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
    $this->manager = senditManager($this->store);
});

it('stores Sendit credentials encrypted at rest', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit',
        'public_key' => 'PUB123',
        'secret_key' => 'super-secret-key',
    ])->assertRedirect();

    $connection = DeliveryConnection::where('store_id', $this->store->id)->where('provider_code', 'sendit')->firstOrFail();

    $raw = DB::table('delivery_connections')->where('id', $connection->id)->value('credentials');

    expect($raw)->not->toContain('super-secret-key')
        ->and($connection->credential('secret_key'))->toBe('super-secret-key');
});

it('never returns the secret_key/token in the Inertia/JSON response shape', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit',
        'public_key' => 'PUB123',
        'secret_key' => 'super-secret-key',
    ]);

    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();
    $shape = $connection->toApiArray();

    expect($shape)->not->toHaveKey('secret_key')
        ->and($shape)->not->toHaveKey('credentials')
        ->and($shape['has_credentials'])->toBeTrue()
        ->and($shape['public_key'])->toBe('PUB123');

    expect(json_encode($shape))->not->toContain('super-secret-key');
});

it('tests the connection via POST /login and stores connected status on success', function () {
    Http::fake(['app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_abc123'], 200)]);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit', 'public_key' => 'PUB123', 'secret_key' => 'super-secret-key',
    ]);

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-connections/sendit/test')
        ->assertRedirect();

    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();
    expect($connection->status)->toBe(DeliveryConnection::STATUS_CONNECTED);

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://app.sendit.ma/api/v1/login')
            ->and($request['public_key'])->toBe('PUB123')
            ->and($request['secret_key'])->toBe('super-secret-key');

        return true;
    });
});

it('stores a clear auth error when Sendit login fails', function () {
    Http::fake(['app.sendit.ma/api/v1/login' => Http::response(['message' => 'Invalid credentials'], 401)]);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit', 'public_key' => 'PUB123', 'secret_key' => 'wrong-secret',
    ]);

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-connections/sendit/test')
        ->assertRedirect()
        ->assertSessionHas('error');

    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();
    expect($connection->status)->toBe(DeliveryConnection::STATUS_ERROR)
        ->and($connection->last_error)->toBe('Invalid credentials');
});

it('never logs the secret_key or bearer token when the login request throws', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
    });
    Log::spy();

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit', 'public_key' => 'PUB123', 'secret_key' => 'super-secret-key',
    ]);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit/test');

    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();
    expect($connection->status)->toBe(DeliveryConnection::STATUS_ERROR);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        $flat = $message . json_encode($context);

        expect($flat)->not->toContain('super-secret-key');

        return true;
    });
});

it('lets a Manager save credentials leaving secret_key blank to keep the existing one', function () {
    Http::fake(['app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_1'], 200)]);

    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit', 'public_key' => 'PUB123', 'secret_key' => 'original-secret',
    ])->assertRedirect();

    // Re-save with a blank secret_key — must keep the original, not reject or blank it.
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit Updated', 'public_key' => 'PUB123',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $connection = DeliveryConnection::where('store_id', $this->store->id)->firstOrFail();
    expect($connection->name)->toBe('Sendit Updated')
        ->and($connection->credential('secret_key'))->toBe('original-secret');
});

it('rejects a store trying to test another store\'s Sendit connection', function () {
    $this->actingAs($this->manager)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit', 'public_key' => 'PUB123', 'secret_key' => 'secret',
    ]);

    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();
    $otherManager = senditManager($otherStore);

    $this->actingAs($otherManager)
        ->post('/dashboard/delivery-connections/sendit/test')
        ->assertNotFound();
});

it('blocks a plain picker from managing the Sendit connection', function () {
    $role = $this->store->roles()->where('name', 'Warehouse')->firstOrFail();
    $picker = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $picker->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($picker)->post('/dashboard/delivery-connections/sendit', [
        'name' => 'Sendit', 'public_key' => 'PUB123', 'secret_key' => 'x',
    ])->assertForbidden();
});
