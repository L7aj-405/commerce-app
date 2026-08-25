<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\Orders\OrderAssignmentService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Confirmation Desk claim-gated actions — an order must be claimed by the
 * CURRENT user before it can be confirmed or cancelled. Enforced server-side
 * (not only by disabled frontend buttons), reusing the existing
 * assigned_to/assigned_at columns (OrderAssignmentService) rather than
 * adding duplicate confirmation_claimed_by/at columns.
 */

/** @return array{0: User, 1: Store} */
function cdClaimWorkspace(string $name = 'Confirmation Claim Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cdClaimMember(Store $store, string $roleSlug): User
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

function cdClaimOnlineOrder(Store $store, string $externalId = 'CLAIM-1'): Order
{
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'api_url' => "https://{$externalId}.example.com",
    ]));

    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'Claim Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

/** Same as cdClaimOnlineOrder() but with a real stocked line item, so a
 * confirm attempt can actually complete allocation (not just pass the
 * claim gate) — needed for the two tests that assert a full confirm. */
function cdClaimStockedOnlineOrder(Store $store, User $actor, string $externalId = 'CLAIM-STOCK-1'): Order
{
    $warehouse = \App\Models\Warehouse::withoutTenancy(fn () => \App\Models\Warehouse::create([
        'user_id' => $actor->id, 'owner_organization_id' => $store->organization_id, 'operator_organization_id' => $store->organization_id,
        'name' => "{$externalId} Warehouse", 'type' => \App\Models\Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$store->organization_id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    $product = \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $store->id, 'name' => "{$externalId} Product", 'sku' => $externalId, 'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]));
    $item = app(\App\Services\Inventory\CatalogInventoryService::class)->forCatalog($product);
    app(\App\Services\Inventory\InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $actor);

    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'api_url' => "https://{$externalId}.example.com",
    ]));

    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'Claim Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('does not let an unclaimed order be confirmed', function (): void {
    // A plain confirmation agent, not the privileged store owner — the
    // owner always carries orders.manage, which is the deliberate
    // supervisor-override escape hatch (see the "supervisor" tests below),
    // so it is never the right actor for proving the claim gate itself.
    [, $store] = cdClaimWorkspace();
    $agent = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimOnlineOrder($store);

    $this->actingAs($agent)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertForbidden();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
});

it('does not let an unclaimed order be cancelled', function (): void {
    [, $store] = cdClaimWorkspace();
    $agent = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimOnlineOrder($store);

    $this->actingAs($agent)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'test'])
        ->assertForbidden();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
});

it('lets the current user claim an order', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $order = cdClaimOnlineOrder($store);

    $this->actingAs($owner)->post("/dashboard/departments/online/{$order->id}/claim")
        ->assertSessionHas('success');

    expect($order->fresh()->assigned_to)->toBe($owner->id);
});

it('lets the current user confirm an order they claimed', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $order = cdClaimStockedOnlineOrder($store, $owner);
    app(OrderAssignmentService::class)->claim($order, $owner);

    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->fulfillment_status)->not->toBe(FulfillmentStatus::Pending);
});

it('lets the current user cancel an order they claimed, with a reason', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $order = cdClaimOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $owner);

    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'Customer unreachable'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Cancelled);
});

it('does not let a different user confirm or cancel an order claimed by someone else', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $agentA = cdClaimMember($store, 'confirmation-agent');
    $agentB = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agentA);

    $this->actingAs($agentB)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertForbidden();
    $this->actingAs($agentB)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'cancelled', 'reason' => 'test'])
        ->assertForbidden();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending)
        ->and($order->fresh()->assigned_to)->toBe($agentA->id);
});

it('does not let two users claim the same order concurrently — the second claim attempt fails', function (): void {
    [, $store] = cdClaimWorkspace();
    $agentA = cdClaimMember($store, 'confirmation-agent');
    $agentB = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimOnlineOrder($store);

    app(OrderAssignmentService::class)->claim($order, $agentA);

    expect(fn () => app(OrderAssignmentService::class)->claim($order->fresh(), $agentB))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect($order->fresh()->assigned_to)->toBe($agentA->id);
});

it('makes the order available again after releasing the claim', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $order = cdClaimOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $owner);

    $this->actingAs($owner)->post("/dashboard/departments/online/{$order->id}/release")
        ->assertSessionHas('success');

    expect($order->fresh()->assigned_to)->toBeNull();
});

it('release claim does not cancel the order', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $order = cdClaimOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $owner);

    $this->actingAs($owner)->post("/dashboard/departments/online/{$order->id}/release");

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending)
        ->and($order->fresh()->status->value)->not->toBe('cancelled');
});

it('does not let another agent release a claim that is not theirs', function (): void {
    [, $store] = cdClaimWorkspace();
    $agentA = cdClaimMember($store, 'confirmation-agent');
    $agentB = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agentA);

    $this->actingAs($agentB)->post("/dashboard/departments/online/{$order->id}/release")
        ->assertForbidden();

    expect($order->fresh()->assigned_to)->toBe($agentA->id);
});

it('lets a supervisor (orders.manage) release another agent\'s claim', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $agentA = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimOnlineOrder($store);
    app(OrderAssignmentService::class)->claim($order, $agentA);

    // The privileged store owner already carries orders.manage.
    $this->actingAs($owner)->post("/dashboard/departments/online/{$order->id}/release")
        ->assertSessionHas('success');

    expect($order->fresh()->assigned_to)->toBeNull();
});

it('lets a supervisor (orders.manage) confirm an order claimed by another agent', function (): void {
    [$owner, $store] = cdClaimWorkspace();
    $agentA = cdClaimMember($store, 'confirmation-agent');
    $order = cdClaimStockedOnlineOrder($store, $owner);
    app(OrderAssignmentService::class)->claim($order, $agentA);

    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->fulfillment_status)->not->toBe(FulfillmentStatus::Pending);
});

it('does not let an agency-scoped user claim an order belonging to an unrelated client/store', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Claim Scope Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);
    $client = $service->createClient($agency, $owner, ['client_name' => 'Claim Scope Client', 'brand_name' => 'Claim Scope Brand', 'country' => 'MA', 'currency' => 'MAD']);
    $clientStore = $client->stores->first();
    $clientStore->ensureDefaultRoles();

    // An unrelated store/organization the agency does not operate at all.
    [, $unrelatedStore] = cdClaimWorkspace('Unrelated Store For Claim Scope');
    $order = cdClaimOnlineOrder($unrelatedStore);

    // Agent belongs to the AGENCY's client org, not the unrelated store.
    $agent = User::factory()->create(['onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $clientStore->id, 'user_id' => $agent->id, 'role' => 'manager',
        'store_role_id' => $clientStore->roles()->where('slug', 'confirmation-agent')->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($client, $agent);

    // The agent's active store is the agency's client store — resolving an
    // order id from a completely unrelated store 404s (never found under
    // that tenant boundary) rather than 403, which is the same "not found,
    // not forbidden" behavior every other cross-store lookup in this app
    // uses; it never even confirms the order exists to the caller.
    $this->actingAs($agent)->post("/dashboard/departments/online/{$order->id}/claim")
        ->assertNotFound();

    expect($order->fresh()->assigned_to)->toBeNull();
});
