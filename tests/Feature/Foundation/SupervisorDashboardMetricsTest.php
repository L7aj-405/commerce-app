<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\AgentActivityEvent;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Metrics\SupervisorDashboardMetricsService;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function supervisorDashboardMetricsWorkspace(string $name = 'Supervisor Metrics Store'): array
{
    $owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);

    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'name' => $name,
    ]);

    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function supervisorDashboardMetricsMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $member->id,
        'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

it('reports open queue sizes per phase, scoped to the store', function (): void {
    [, $store] = supervisorDashboardMetricsWorkspace();
    [, $otherStore] = supervisorDashboardMetricsWorkspace('Other Supervisor Store');
    $supervisor = supervisorDashboardMetricsMember($store, 'supervisor');

    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Pending]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Pending]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Picking]);
    // Completed is phase 'closed' — fully finished orders must not inflate
    // any open queue (Delivered, by contrast, is phase 'delivery', so it
    // legitimately counts toward that queue — not used here for that reason).
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Completed]);
    Order::factory()->for($otherStore)->create(['fulfillment_status' => FulfillmentStatus::Pending]);

    $result = app(SupervisorDashboardMetricsService::class)->build(
        $supervisor,
        $store,
        now()->startOfDay(),
        now()->endOfDay()
    );

    expect($result['queues']['confirmation'])->toBe(2)
        ->and($result['queues']['fulfillment'])->toBe(1)
        ->and($result['queues']['delivery'])->toBe(0);
});

it('counts orders waiting for stock separately from the general fulfillment queue', function (): void {
    [, $store] = supervisorDashboardMetricsWorkspace('Waiting Stock Supervisor Store');
    $supervisor = supervisorDashboardMetricsMember($store, 'supervisor');

    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::WaitingForStock]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::WaitingForStock]);

    $result = app(SupervisorDashboardMetricsService::class)->build(
        $supervisor,
        $store,
        now()->startOfDay(),
        now()->endOfDay()
    );

    expect($result['waiting_stock_count'])->toBe(2);
});

it('flags orders open past the 24h threshold as delayed but not closed/cancelled orders', function (): void {
    [, $store] = supervisorDashboardMetricsWorkspace('Delayed Supervisor Store');
    $supervisor = supervisorDashboardMetricsMember($store, 'supervisor');

    Order::factory()->for($store)->create([
        'fulfillment_status' => FulfillmentStatus::Pending,
        'created_at' => now()->subHours(30),
    ]);

    Order::factory()->for($store)->create([
        'fulfillment_status' => FulfillmentStatus::Pending,
        'created_at' => now()->subHours(2),
    ]);

    Order::factory()->for($store)->create([
        'fulfillment_status' => FulfillmentStatus::Completed,
        'created_at' => now()->subHours(30),
    ]);

    Order::factory()->for($store)->create([
        'fulfillment_status' => FulfillmentStatus::Cancelled,
        'created_at' => now()->subHours(30),
    ]);

    $result = app(SupervisorDashboardMetricsService::class)->build(
        $supervisor,
        $store,
        now()->startOfDay(),
        now()->endOfDay()
    );

    expect($result['delayed_orders_count'])->toBe(1);
});

it('aggregates team activity today across every agent in the store', function (): void {
    [, $store] = supervisorDashboardMetricsWorkspace('Team Activity Supervisor Store');
    $supervisor = supervisorDashboardMetricsMember($store, 'supervisor');

    $agentA = User::factory()->create();
    $agentB = User::factory()->create();

    AgentActivityEvent::create([
        'organization_id' => $store->organization_id,
        'store_id' => $store->id,
        'user_id' => $agentA->id,
        'event_type' => AgentActivityEvent::CONFIRMATION_CONFIRMED,
        'source_module' => 'confirmation',
        'occurred_at' => now(),
    ]);

    AgentActivityEvent::create([
        'organization_id' => $store->organization_id,
        'store_id' => $store->id,
        'user_id' => $agentB->id,
        'event_type' => AgentActivityEvent::CONFIRMATION_CONFIRMED,
        'source_module' => 'confirmation',
        'occurred_at' => now(),
    ]);

    AgentActivityEvent::create([
        'organization_id' => $store->organization_id,
        'store_id' => $store->id,
        'user_id' => $agentA->id,
        'event_type' => AgentActivityEvent::FULFILLMENT_PICKED,
        'source_module' => 'fulfillment',
        'occurred_at' => now(),
    ]);

    $result = app(SupervisorDashboardMetricsService::class)->build(
        $supervisor,
        $store,
        now()->startOfDay(),
        now()->endOfDay()
    );

    expect($result['team_activity_today'][AgentActivityEvent::CONFIRMATION_CONFIRMED])->toBe(2)
        ->and($result['team_activity_today'][AgentActivityEvent::FULFILLMENT_PICKED])->toBe(1);
});

it('only lists agents holding the matching queue permission in the leaderboard', function (): void {
    [, $store] = supervisorDashboardMetricsWorkspace('Leaderboard Supervisor Store');

    $supervisor = supervisorDashboardMetricsMember($store, 'supervisor');
    $confirmationAgent = supervisorDashboardMetricsMember($store, 'confirmation-agent');
    $warehouseAgent = supervisorDashboardMetricsMember($store, 'warehouse');

    Order::factory()->for($store)->create([
        'fulfillment_status' => FulfillmentStatus::Pending,
        'assigned_to' => $confirmationAgent->id,
    ]);

    $result = app(SupervisorDashboardMetricsService::class)->build(
        $supervisor,
        $store,
        now()->startOfDay(),
        now()->endOfDay()
    );

    $confirmationIds = collect($result['leaderboard']['confirmation'])->pluck('id');
    $fulfillmentIds = collect($result['leaderboard']['fulfillment'])->pluck('id');

    expect($confirmationIds)->toContain($confirmationAgent->id)
        ->and($confirmationIds)->not->toContain($warehouseAgent->id)
        ->and($fulfillmentIds)->toContain($warehouseAgent->id)
        ->and($fulfillmentIds)->not->toContain($confirmationAgent->id);
});