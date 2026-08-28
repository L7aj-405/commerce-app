<?php

declare(strict_types=1);

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
 * The Orders board / Confirmation Desk cards must never show an enabled
 * Confirm/Cancel action that the backend would reject — OrderPresenter::
 * claimState() is the single source of these flags (assigned_to,
 * assigned_user_name, claimed_by_current_user, can_claim, can_confirm,
 * can_cancel), consumed by both /dashboard/orders/manage and
 * /dashboard/departments/confirmation so the two pages can never disagree.
 */

/** @return array{0: User, 1: Store} */
function ccatWorkspace(string $name = 'Confirmation Card Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ccatMember(Store $store, string $roleSlug): User
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

function ccatOnlineOrder(Store $store, string $externalId = 'CCAT-1'): Order
{
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'api_url' => "https://{$externalId}.example.com",
    ]));

    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'Card Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

function ccatFindRow(array $orders, string $orderId): ?array
{
    foreach ($orders as $row) {
        if ($row['id'] === $orderId) {
            return $row;
        }
    }

    return null;
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('marks an unclaimed pending order as can_confirm=false and can_claim=true on the orders board', function (): void {
    [, $store] = ccatWorkspace();
    $agent = ccatMember($store, 'confirmation-agent');
    $order = ccatOnlineOrder($store);

    $this->actingAs($agent)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(function ($page) use ($order) {
            $row = ccatFindRow($page->toArray()['props']['orders'], $order->id);

            expect($row)->not->toBeNull()
                ->and($row['assigned_to'])->toBeNull()
                ->and($row['claimed_by_current_user'])->toBeFalse()
                ->and($row['can_claim'])->toBeTrue()
                ->and($row['can_confirm'])->toBeFalse()
                ->and($row['can_cancel'])->toBeFalse();

            return $page;
        });
});

it('marks an order claimed by the current user as can_confirm=true', function (): void {
    [, $store] = ccatWorkspace('Claimed By Me Card Store');
    $agent = ccatMember($store, 'confirmation-agent');
    $order = ccatOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agent);

    $this->actingAs($agent)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(function ($page) use ($order) {
            $row = ccatFindRow($page->toArray()['props']['orders'], $order->id);

            expect($row)->not->toBeNull()
                ->and($row['claimed_by_current_user'])->toBeTrue()
                ->and($row['can_claim'])->toBeFalse()
                ->and($row['can_confirm'])->toBeTrue()
                ->and($row['can_cancel'])->toBeTrue();

            return $page;
        });
});

it('marks an order claimed by ANOTHER agent as can_confirm=false and can_claim=false for a normal agent', function (): void {
    [, $store] = ccatWorkspace('Claimed By Other Card Store');
    $agentA = ccatMember($store, 'confirmation-agent');
    $agentB = ccatMember($store, 'confirmation-agent');
    $order = ccatOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agentA);

    $this->actingAs($agentB)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(function ($page) use ($order) {
            $row = ccatFindRow($page->toArray()['props']['orders'], $order->id);

            expect($row)->not->toBeNull()
                ->and($row['assigned_to'])->not->toBeNull()
                ->and($row['claimed_by_current_user'])->toBeFalse()
                ->and($row['can_claim'])->toBeFalse()
                ->and($row['can_confirm'])->toBeFalse()
                ->and($row['can_cancel'])->toBeFalse()
                ->and($row['assignee_name'])->not->toBeNull();

            return $page;
        });
});

it('lets a supervisor (orders.manage) act on an order claimed by another agent', function (): void {
    [$owner, $store] = ccatWorkspace('Supervisor Card Store');
    $agentA = ccatMember($store, 'confirmation-agent');
    $order = ccatOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agentA);

    // The privileged store owner already carries orders.manage.
    $this->actingAs($owner)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(function ($page) use ($order) {
            $row = ccatFindRow($page->toArray()['props']['orders'], $order->id);

            expect($row)->not->toBeNull()
                ->and($row['claimed_by_current_user'])->toBeFalse()
                ->and($row['can_confirm'])->toBeTrue()
                ->and($row['can_cancel'])->toBeTrue();

            return $page;
        });
});

it('exposes the same claim flags on the Confirmation Desk department queue', function (): void {
    [, $store] = ccatWorkspace('Confirmation Desk Card Store');
    $agent = ccatMember($store, 'confirmation-agent');
    $order = ccatOnlineOrder($store);

    $this->actingAs($agent)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(function ($page) use ($order) {
            $row = ccatFindRow($page->toArray()['props']['orders'], $order->id);

            expect($row)->not->toBeNull()
                ->and($row['can_claim'])->toBeTrue()
                ->and($row['can_confirm'])->toBeFalse();

            return $page;
        });
});

it('keeps the existing row shape (assigned_to/assignee_name) alongside the new claim fields', function (): void {
    [, $store] = ccatWorkspace('Shape Unchanged Card Store');
    $agent = ccatMember($store, 'confirmation-agent');
    $order = ccatOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agent);

    $this->actingAs($agent)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(function ($page) use ($order) {
            $row = ccatFindRow($page->toArray()['props']['orders'], $order->id);

            expect($row)->toHaveKeys([
                'assigned_to', 'assignee_name', 'assigned_user_name',
                'claimed_by_current_user', 'can_claim', 'can_confirm', 'can_cancel',
            ])->and($row['assigned_user_name'])->toBe($row['assignee_name']);

            return $page;
        });
});
