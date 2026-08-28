<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Events\OrderCreated;
use App\Models\AgentActivityEvent;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderAssignmentService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * The agent_activity_events ledger is written additively, after a workflow
 * action already succeeded, and ONLY when a real actor performed it — see
 * App\Services\Activity\AgentActivityRecorder and its call sites.
 */

/** @return array{0: User, 1: Store} */
function aaeWorkspace(string $name = 'Activity Ledger Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function aaeMember(Store $store, string $roleSlug): User
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

function aaeOnlineOrder(Store $store, string $externalId = 'AAE-1'): Order
{
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'api_url' => "https://{$externalId}.example.com",
    ]));

    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'Ledger Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('writes a confirmation.claimed event when an agent claims an order', function (): void {
    [, $store] = aaeWorkspace();
    $agent = aaeMember($store, 'confirmation-agent');
    $order = aaeOnlineOrder($store);

    app(OrderAssignmentService::class)->claim($order, $agent);

    expect(AgentActivityEvent::query()->ofType(AgentActivityEvent::CONFIRMATION_CLAIMED)->count())->toBe(1);
    $event = AgentActivityEvent::query()->ofType(AgentActivityEvent::CONFIRMATION_CLAIMED)->first();
    expect($event->user_id)->toBe($agent->id)
        ->and($event->store_id)->toBe($store->id)
        ->and($event->order_id)->toBe($order->id)
        ->and($event->role_context)->toBe('confirmation-agent');
});

it('does not double-log when the same agent re-claims their own already-held order', function (): void {
    [, $store] = aaeWorkspace();
    $agent = aaeMember($store, 'confirmation-agent');
    $order = aaeOnlineOrder($store);

    app(OrderAssignmentService::class)->claim($order, $agent);
    app(OrderAssignmentService::class)->claim($order->fresh(), $agent);

    expect(AgentActivityEvent::query()->ofType(AgentActivityEvent::CONFIRMATION_CLAIMED)->count())->toBe(1);
});

it('writes a confirmation.confirmed event when an actor confirms an order', function (): void {
    [$owner, $store] = aaeWorkspace();
    $order = aaeOnlineOrder($store);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, $owner);

    expect(AgentActivityEvent::query()->ofType(AgentActivityEvent::CONFIRMATION_CONFIRMED)->count())->toBe(1);
    $event = AgentActivityEvent::query()->ofType(AgentActivityEvent::CONFIRMATION_CONFIRMED)->first();
    expect($event->user_id)->toBe($owner->id)->and($event->order_id)->toBe($order->id);
});

it('writes a confirmation.cancelled event when an actor cancels a pending order', function (): void {
    [$owner, $store] = aaeWorkspace();
    $order = aaeOnlineOrder($store);

    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Cancelled, $owner, 'test reason');

    expect(AgentActivityEvent::query()->ofType(AgentActivityEvent::CONFIRMATION_CANCELLED)->count())->toBe(1);
});

it('never writes an activity event when there is no actor (the WhatsApp customer-reply path)', function (): void {
    [, $store] = aaeWorkspace();
    $order = aaeOnlineOrder($store);

    // Mirrors Order::markAsConfirmed()/markAsCancelled(), which call
    // transition() with no $actor for a customer's own WhatsApp reply.
    app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Confirmed, actor: null);

    expect(AgentActivityEvent::query()->count())->toBe(0);
});

it('does not log a failed/rejected transition as activity', function (): void {
    [$owner, $store] = aaeWorkspace();
    $order = aaeOnlineOrder($store);

    // Delivered is not a legal move from Pending.
    expect(fn () => app(OrderWorkflowService::class)->transition($order, FulfillmentStatus::Delivered, $owner))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(AgentActivityEvent::query()->count())->toBe(0);
});

it('scopes activity events to the correct organization and store', function (): void {
    [$ownerA, $storeA] = aaeWorkspace('Ledger Store A');
    [$ownerB, $storeB] = aaeWorkspace('Ledger Store B');
    $orderA = aaeOnlineOrder($storeA, 'AAE-A');
    $orderB = aaeOnlineOrder($storeB, 'AAE-B');

    app(OrderWorkflowService::class)->transition($orderA, FulfillmentStatus::Confirmed, $ownerA);
    app(OrderWorkflowService::class)->transition($orderB, FulfillmentStatus::Confirmed, $ownerB);

    expect(AgentActivityEvent::query()->forStore($storeA->id)->count())->toBe(1)
        ->and(AgentActivityEvent::query()->forStore($storeA->id)->first()->organization_id)->toBe($storeA->organization_id)
        ->and(AgentActivityEvent::query()->forStore($storeB->id)->count())->toBe(1);
});
