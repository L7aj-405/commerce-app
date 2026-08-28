<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function fadWorkspace(string $name = 'Fulfillment Agent Dashboard Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function fadAgent(Store $store): User
{
    $agent = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $agent->id, 'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', 'warehouse')->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $agent);

    return $agent;
}

it('shows the pick queue header with real queue counts', function (): void {
    [, $store] = fadWorkspace();
    $agent = fadAgent($store);

    $this->actingAs($agent)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'fulfillment')
            ->has('assigned_to_me_count')
            ->has('waiting_stock_count')
            ->has('ready_for_dispatch_count')
            ->has('today.picked_units_count')
            ->has('today.average_pack_time_seconds')
            ->has('week')
            ->has('points_preview'));
});

it('only counts orders assigned to me, not to a fellow warehouse agent', function (): void {
    [, $store] = fadWorkspace('Assigned To Me Store');
    $agentA = fadAgent($store);
    $agentB = fadAgent($store);

    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Picking, 'assigned_to' => $agentA->id]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Packing, 'assigned_to' => $agentA->id]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Picking, 'assigned_to' => $agentB->id]);

    $this->actingAs($agentA)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('assigned_to_me_count', 2));
});

it('reports store-wide waiting-for-stock and ready-for-dispatch counts, not agent-scoped', function (): void {
    [, $store] = fadWorkspace('Store Wide Counts Store');
    $agentA = fadAgent($store);
    $agentB = fadAgent($store);

    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::WaitingForStock, 'assigned_to' => $agentB->id]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::ReadyForDelivery, 'assigned_to' => $agentB->id]);

    $this->actingAs($agentA)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('waiting_stock_count', 1)
            ->where('ready_for_dispatch_count', 1));
});
