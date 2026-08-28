<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderAssignmentService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Backend authorization for order actions stays strict no matter what the
 * frontend shows — these tests exercise the raw HTTP layer (no X-Inertia
 * header, i.e. exactly how a plain Pest/API caller or a pre-JS request
 * behaves) to prove the claim gate itself is unweakened by the UX work in
 * App\Support\InertiaErrorResponder. See InertiaForbiddenActionTest for the
 * companion behavior specific to genuine Inertia SPA actions.
 */

/** @return array{0: User, 1: Store} */
function oauxWorkspace(string $name = 'Order Action UX Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function oauxMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'manager',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

function oauxOnlineOrder(Store $store, string $externalId = 'OAUX-1', ?PlatformConnection $connection = null): Order
{
    $connection ??= PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'api_url' => "https://{$externalId}.example.com",
    ]));

    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'UX Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('does not let a confirmation agent confirm an order that is not claimed by them', function (): void {
    [, $store] = oauxWorkspace();
    $agent = oauxMember($store, 'confirmation-agent');
    $order = oauxOnlineOrder($store);

    $this->actingAs($agent)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertForbidden();
});

it('performs no workflow change when an unclaimed confirm is rejected', function (): void {
    [, $store] = oauxWorkspace();
    $agent = oauxMember($store, 'confirmation-agent');
    $order = oauxOnlineOrder($store);

    $this->actingAs($agent)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertForbidden();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending)
        ->and($order->fresh()->status->value)->toBe('pending');
});

it('performs no workflow change when an unclaimed cancel is rejected', function (): void {
    [, $store] = oauxWorkspace();
    $agent = oauxMember($store, 'confirmation-agent');
    $order = oauxOnlineOrder($store);

    $this->actingAs($agent)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'test'])
        ->assertForbidden();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
});

it('still rejects a confirm attempt by an agent who claimed a DIFFERENT order', function (): void {
    [, $store] = oauxWorkspace();
    $agentA = oauxMember($store, 'confirmation-agent');
    $agentB = oauxMember($store, 'confirmation-agent');
    $orderA = oauxOnlineOrder($store, 'OAUX-A');
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::first());
    $orderB = oauxOnlineOrder($store, 'OAUX-B', $connection);
    app(OrderAssignmentService::class)->claim($orderA, $agentA);

    $this->actingAs($agentA)->post("/dashboard/orders/online/{$orderB->id}/status", ['status' => 'confirmed'])
        ->assertForbidden();

    expect($orderB->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
});

it('the exact reported error message is returned for an unclaimed confirm attempt', function (): void {
    [, $store] = oauxWorkspace();
    $agent = oauxMember($store, 'confirmation-agent');
    $order = oauxOnlineOrder($store);

    $response = $this->actingAs($agent)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed']);

    $response->assertForbidden();
    expect($response->exception->getMessage())->toBe('Claim this order before confirming or cancelling it.');
});
