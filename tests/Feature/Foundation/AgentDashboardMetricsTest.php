<?php

declare(strict_types=1);

use App\Models\AgentActivityEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\Metrics\AgentDashboardMetricsService;
use App\Services\OrganizationProvisioner;

/**
 * Every number AgentDashboardMetricsService reports is derived straight from
 * the agent_activity_events ledger — these tests write ledger rows directly
 * (the ledger's own writer is covered by AgentActivityEventTest) and assert
 * the arithmetic on top of them.
 */

/** @return array{0: User, 1: Store} */
function admWorkspace(string $name = 'Metrics Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);

    return [$owner, $store];
}

function admEvent(Store $store, User $user, string $type, ?string $orderId, array $metadata, \Carbon\CarbonInterface $occurredAt): AgentActivityEvent
{
    return AgentActivityEvent::create([
        'organization_id' => $store->organization_id,
        'store_id' => $store->id,
        'user_id' => $user->id,
        'role_context' => 'confirmation-agent',
        'event_type' => $type,
        'order_id' => $orderId,
        'source_module' => 'confirmation',
        'metadata' => $metadata,
        'occurred_at' => $occurredAt,
    ]);
}

function admRange(): array
{
    return ['from' => now()->startOfDay(), 'to' => now()->endOfDay()];
}

it('computes confirmation rate and average handling time from paired claim/confirm events', function (): void {
    [, $store] = admWorkspace();
    $agent = User::factory()->create();
    $service = app(AgentDashboardMetricsService::class);
    ['from' => $from, 'to' => $to] = admRange();

    admEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CLAIMED, 'order-1', [], now()->subMinutes(3));
    admEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CONFIRMED, 'order-1', [], now()->subMinutes(1));
    admEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CLAIMED, 'order-2', [], now()->subMinutes(5));
    admEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CANCELLED, 'order-2', [], now()->subMinutes(4));

    $metrics = $service->confirmationMetrics($agent, $store, $from, $to);

    expect($metrics['confirmed_count'])->toBe(1)
        ->and($metrics['cancelled_count'])->toBe(1)
        ->and($metrics['confirmation_rate'])->toBe(50.0)
        ->and($metrics['average_confirmation_time_seconds'])->toBe(120.0);
});

it('returns null averages and zero rates when there is no activity yet', function (): void {
    [, $store] = admWorkspace('Empty Metrics Store');
    $agent = User::factory()->create();
    $service = app(AgentDashboardMetricsService::class);
    ['from' => $from, 'to' => $to] = admRange();

    $metrics = $service->confirmationMetrics($agent, $store, $from, $to);

    expect($metrics['confirmed_count'])->toBe(0)
        ->and($metrics['confirmation_rate'])->toBeNull()
        ->and($metrics['average_confirmation_time_seconds'])->toBeNull();
});

it('sums picked and packed units from event metadata', function (): void {
    [, $store] = admWorkspace('Units Metrics Store');
    $agent = User::factory()->create();
    $service = app(AgentDashboardMetricsService::class);
    ['from' => $from, 'to' => $to] = admRange();

    admEvent($store, $agent, AgentActivityEvent::FULFILLMENT_PICKED, 'order-1', ['units' => 3], now()->subMinutes(10));
    admEvent($store, $agent, AgentActivityEvent::FULFILLMENT_PACKED, 'order-1', ['units' => 3], now()->subMinutes(8));
    admEvent($store, $agent, AgentActivityEvent::FULFILLMENT_PICKED, 'order-2', ['units' => 2], now()->subMinutes(6));

    $metrics = $service->fulfillmentMetrics($agent, $store, $from, $to);

    expect($metrics['picked_orders_count'])->toBe(2)
        ->and($metrics['picked_units_count'])->toBe(5)
        ->and($metrics['packed_orders_count'])->toBe(1)
        ->and($metrics['packed_units_count'])->toBe(3)
        ->and($metrics['average_pack_time_seconds'])->toBe(120.0);
});

it('computes delivery success rate and sums COD collected', function (): void {
    [, $store] = admWorkspace('Delivery Metrics Store');
    $agent = User::factory()->create();
    $service = app(AgentDashboardMetricsService::class);
    ['from' => $from, 'to' => $to] = admRange();

    admEvent($store, $agent, AgentActivityEvent::DELIVERY_DELIVERED, 'order-1', ['cod_collected' => 150.0], now()->subMinutes(30));
    admEvent($store, $agent, AgentActivityEvent::DELIVERY_DELIVERED, 'order-2', ['cod_collected' => 50.0], now()->subMinutes(20));
    admEvent($store, $agent, AgentActivityEvent::DELIVERY_FAILED, 'order-3', [], now()->subMinutes(10));

    $metrics = $service->deliveryMetrics($agent, $store, $from, $to);

    expect($metrics['delivered_count'])->toBe(2)
        ->and($metrics['failed_count'])->toBe(1)
        ->and($metrics['cod_collected'])->toBe(200.0)
        ->and($metrics['delivery_success_rate'])->toBe(66.7);
});

it('never mixes another agent or another store into a single agent metrics query', function (): void {
    [, $storeA] = admWorkspace('Scope Store A');
    [, $storeB] = admWorkspace('Scope Store B');
    $agentA = User::factory()->create();
    $agentB = User::factory()->create();
    $service = app(AgentDashboardMetricsService::class);
    ['from' => $from, 'to' => $to] = admRange();

    admEvent($storeA, $agentA, AgentActivityEvent::CONFIRMATION_CONFIRMED, 'order-1', [], now());
    admEvent($storeA, $agentB, AgentActivityEvent::CONFIRMATION_CONFIRMED, 'order-2', [], now());
    admEvent($storeB, $agentA, AgentActivityEvent::CONFIRMATION_CONFIRMED, 'order-3', [], now());

    $metrics = $service->confirmationMetrics($agentA, $storeA, $from, $to);

    expect($metrics['confirmed_count'])->toBe(1);
});
