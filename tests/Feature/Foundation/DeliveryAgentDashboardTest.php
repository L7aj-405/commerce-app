<?php

declare(strict_types=1);

use App\Models\AgentActivityEvent;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function dadWorkspace(string $name = 'Delivery Agent Dashboard Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function dadDispatcher(Store $store): User
{
    $dispatcher = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $dispatcher->id, 'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', 'dispatcher')->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $dispatcher);

    return $dispatcher;
}

it('shows the deliveries header with today/week metrics and a points preview', function (): void {
    [, $store] = dadWorkspace();
    $dispatcher = dadDispatcher($store);

    $this->actingAs($dispatcher)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'delivery')
            ->has('today.delivered_count')
            ->has('today.delivery_success_rate')
            ->has('today.cod_collected')
            ->has('week')
            ->has('returns_to_inspect_count')
            ->has('points_preview'));
});

it('only counts my own delivery outcomes, not a fellow dispatcher\'s', function (): void {
    [, $store] = dadWorkspace('My Own Outcomes Store');
    $dispatcherA = dadDispatcher($store);
    $dispatcherB = dadDispatcher($store);

    AgentActivityEvent::create([
        'organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $dispatcherA->id,
        'event_type' => AgentActivityEvent::DELIVERY_DELIVERED, 'order_id' => 'order-a',
        'source_module' => 'delivery', 'metadata' => ['cod_collected' => 80.0], 'occurred_at' => now(),
    ]);
    AgentActivityEvent::create([
        'organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $dispatcherB->id,
        'event_type' => AgentActivityEvent::DELIVERY_DELIVERED, 'order_id' => 'order-b',
        'source_module' => 'delivery', 'metadata' => ['cod_collected' => 999.0], 'occurred_at' => now(),
    ]);

    $this->actingAs($dispatcherA)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('today.delivered_count', 1)
            // Inertia's JSON transport encodes a whole-number float like 80.0
            // as "80", so it decodes back as an int — compare numerically
            // rather than asserting an exact float type.
            ->where('today.cod_collected', fn ($value) => (float) $value === 80.0));
});
