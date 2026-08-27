<?php

declare(strict_types=1);

use App\Models\DeliveryConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sendit labels — POST /deliveries/getlabels, storing/returning the file URL.
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
});

it('fetches labels for one or more codes and stores/returns the file URL', function () {
    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_labels'], 200),
        'app.sendit.ma/api/v1/deliveries/getlabels' => Http::response([
            'success' => true,
            'data' => ['fileUrl' => 'https://app.sendit.ma/files/labels-abc.pdf'],
        ], 200),
    ]);

    $this->actingAs($this->manager)
        ->postJson('/dashboard/delivery-connections/sendit/labels', [
            'codes' => ['SND-1', 'SND-2'],
            'print_format' => 1,
        ])
        ->assertOk()
        ->assertJson(['ok' => true, 'file_url' => 'https://app.sendit.ma/files/labels-abc.pdf']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'getlabels')) {
            return false;
        }

        expect($request['codesToPrint'])->toBe('SND-1,SND-2')
            ->and($request['printFormat'])->toBe(1);

        return true;
    });
});

it('reports a clear error when Sendit does not return a file URL', function () {
    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_labels'], 200),
        'app.sendit.ma/api/v1/deliveries/getlabels' => Http::response(['success' => true, 'data' => []], 200),
    ]);

    $this->actingAs($this->manager)
        ->postJson('/dashboard/delivery-connections/sendit/labels', ['codes' => ['SND-1']])
        ->assertOk()
        ->assertJson(['ok' => true, 'file_url' => null]);
});

it('surfaces a Sendit rejection for the labels request', function () {
    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_labels'], 200),
        'app.sendit.ma/api/v1/deliveries/getlabels' => Http::response(['success' => false, 'message' => 'Unknown code'], 200),
    ]);

    $this->actingAs($this->manager)
        ->postJson('/dashboard/delivery-connections/sendit/labels', ['codes' => ['SND-BAD']])
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'message' => 'Unknown code']);
});

it('requires at least one code', function () {
    $this->actingAs($this->manager)
        ->postJson('/dashboard/delivery-connections/sendit/labels', ['codes' => []])
        ->assertStatus(422);
});

it('blocks another store from fetching labels through this connection', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    $this->actingAs($otherOwner)
        ->postJson('/dashboard/delivery-connections/sendit/labels', ['codes' => ['SND-1']])
        ->assertStatus(404);
});
