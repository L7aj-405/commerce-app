<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function cadWorkspace(string $name = 'Confirmation Agent Dashboard Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cadAgent(Store $store): User
{
    $agent = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $agent->id, 'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', 'confirmation-agent')->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $agent);

    return $agent;
}

it('shows the confirmation desk header and claim action', function (): void {
    [, $store] = cadWorkspace();
    $agent = cadAgent($store);

    $this->actingAs($agent)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'confirmation')
            ->has('waiting_count')
            ->has('claimed_by_me_count')
            ->has('today.confirmed_count')
            ->has('today.average_confirmation_time_seconds')
            ->has('week')
            ->has('month')
            ->has('points_preview'));
});

it('counts only unassigned pending orders as waiting, and only my own claims as claimed by me', function (): void {
    [, $store] = cadWorkspace('Waiting Vs Claimed Store');
    $agentA = cadAgent($store);
    $agentB = cadAgent($store);

    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Pending, 'assigned_to' => null]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Pending, 'assigned_to' => $agentA->id]);
    Order::factory()->for($store)->create(['fulfillment_status' => FulfillmentStatus::Pending, 'assigned_to' => $agentB->id]);

    $this->actingAs($agentA)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('waiting_count', 1)
            ->where('claimed_by_me_count', 1));
});
