<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceOrderTransactionService;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization}
 */
function codWorkspace(string $name = 'COD Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    return [$owner, $store, $organization];
}

it('only lists pending COD orders belonging to the active organization', function (): void {
    [$ownerA, $storeA, $orgA] = codWorkspace('COD Org A');
    [, $storeB, $orgB] = codWorkspace('COD Org B');

    $orderA = Order::factory()->create(['store_id' => $storeA->id, 'organization_id' => $orgA->id]);
    $orderB = Order::factory()->create(['store_id' => $storeB->id, 'organization_id' => $orgB->id]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderA);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderB);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/cod-receivables')->assertOk();
    $orderNumbers = collect($response->viewData('page')['props']['orders'])->pluck('order_number');

    expect($orderNumbers)->toContain($orderA->order_number)->and($orderNumbers)->not->toContain($orderB->order_number);
});

it('excludes an order from the pending list once it has been collected', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();
    app(FinanceOrderTransactionService::class)->markCodCollected($order, $cash, $owner, (float) $order->total, now());

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $orderNumbers = collect($response->viewData('page')['props']['orders'])->pluck('order_number');

    expect($orderNumbers)->not->toContain($order->order_number);
});

it('marking a COD order collected creates exactly one cod_collected transaction', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id,
        'amount_collected' => (float) $order->total,
        'collected_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $rows = FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->direction->value)->toBe('in')
        ->and((float) $rows->first()->amount)->toBe((float) $order->total);
});

it('is idempotent when the same COD order is marked collected twice', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $payload = ['account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString()];

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", $payload);
    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", $payload);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->count())->toBe(1);
});

it('denies a staff member without finance.mark_collected from collecting COD orders', function (): void {
    [, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $limitedRole = StoreRole::create([
        'store_id' => $store->id, 'name' => 'Finance Viewer', 'permissions' => ['finance.view'], 'is_system' => false,
    ]);
    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $staff->id, 'role' => 'manager',
        'store_role_id' => $limitedRole->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($staff)->get('/dashboard/finance/cod-receivables')->assertOk();
    $this->actingAs($staff)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ])->assertForbidden();
});

it('rejects an account that belongs to another organization', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Reject A');
    [, , $orgB] = codWorkspace('COD Reject B');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    $foreignAccount = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $foreignAccount->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ])->assertSessionHasErrors('account_id');
});

it('rejects an order that belongs to another organization', function (): void {
    [$ownerA, , $orgA] = codWorkspace('COD Order Reject A');
    [, $storeB, $orgB] = codWorkspace('COD Order Reject B');
    $orderB = Order::factory()->create(['store_id' => $storeB->id, 'organization_id' => $orgB->id]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderB);
    $cashA = FinanceAccount::where('organization_id', $orgA->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($ownerA)->post("/dashboard/finance/cod-receivables/{$orderB->id}/mark-collected", [
        'account_id' => $cashA->id, 'amount_collected' => (float) $orderB->total, 'collected_at' => now()->toDateString(),
    ])->assertStatus(404);
});

it('renders the COD receivables page without a 500 and groups by external carrier, store, order, customer, amount and status', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD External Carrier Store');
    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'customer_name' => 'Aicha Bennani', 'customer_phone' => '0611223344',
    ]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'receiver_name' => 'Aicha Bennani', 'phone' => '0611223344', 'address' => 'Casablanca',
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstOrFail();

    expect($row['order_number'])->toBe($order->order_number)
        ->and($row['customer_name'])->toBe('Aicha Bennani')
        ->and($row['store']['name'])->toBe($store->name)
        ->and((float) $row['total'])->toBe((float) $order->total)
        ->and($row['fulfillment_status'])->not->toBeNull()
        ->and($row['external_carrier'])->toBe('Ozon Express')
        ->and($row['internal_courier'])->toBeNull();
});

it('groups a COD order by its internal courier when dispatched to an in-house agent', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Internal Courier Store');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    $agent = User::factory()->create(['name' => 'Youssef the Driver', 'onboarding_completed_at' => now()]);
    OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-1', 'carrier_type' => OrderShipment::CARRIER_INTERNAL, 'agent_id' => $agent->id,
        'status' => OrderShipment::STATUS_DISPATCHED, 'cod_amount' => $order->total,
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstOrFail();

    expect($row['internal_courier'])->toBe('Youssef the Driver')
        ->and($row['external_carrier'])->toBeNull();
});

it('lists a pending COD order placed against a sibling store in the same organization', function (): void {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Multi-store Org');
    $storeA = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => 'Store A']);
    $storeA->ensureDefaultRoles();
    $storeB = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => 'Store B']);
    $storeB->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    // One pending COD order per store — whichever store ends up "active" for
    // the request, BOTH must still show up (Finance is organization-scoped).
    $orderA = Order::factory()->create(['store_id' => $storeA->id, 'organization_id' => $organization->id]);
    $orderB = Order::factory()->create(['store_id' => $storeB->id, 'organization_id' => $organization->id]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderA);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderB);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $orderNumbers = collect($response->viewData('page')['props']['orders'])->pluck('order_number');

    expect($orderNumbers)->toContain($orderA->order_number)->and($orderNumbers)->toContain($orderB->order_number);
});

it('shows a confirmed-only COD order as pending but not collectable', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Confirmed]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row)->not->toBeNull()
        ->and($row['is_collectable'])->toBeFalse()
        ->and($row['collectability_status'])->toBe('not_delivered')
        ->and($row['reason'])->toBe('This COD order cannot be collected yet because it has not been delivered.')
        ->and($row['delivery_stage'])->toBe('Confirmed');
});

it('shows picking/packing/ready-for-delivery COD orders as pending but not collectable', function (): void {
    [$owner, $store, $organization] = codWorkspace();

    foreach ([FulfillmentStatus::Picking, FulfillmentStatus::Packing, FulfillmentStatus::ReadyForDelivery] as $status) {
        $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => $status]);
        app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

        $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
        $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

        expect($row['is_collectable'])->toBeFalse()
            ->and($row['collectability_status'])->not->toBe('delivered_collectable')
            ->and($row['delivery_stage'])->toBe($status->label());
    }
});

it('a delivered COD order becomes collectable', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['is_collectable'])->toBeTrue()
        ->and($row['collectability_status'])->toBe('delivered_collectable')
        ->and($row['delivery_stage'])->toBe('Delivered');
});

it('rejects marking a non-delivered COD order collected, even via a forged direct request, without a 500', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Confirmed]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $response = $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ]);

    $response->assertSessionHasErrors();
    expect($response->status())->not->toBe(500);
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeFalse();
});

it('refuses to mark a COD order collected a second time through a different mechanism once already settled', function (): void {
    [$owner, $store, $organization] = codWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    // Closed via an external settlement first.
    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ]);
    $settlement = \App\Models\FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    $this->actingAs($owner)->post("/dashboard/finance/cod-settlements/{$settlement->id}/settle");

    // Now attempting the ad-hoc single-order "mark collected" action must be
    // refused — it would otherwise double-count cash on top of the
    // settlement's net cash-in entry.
    $response = $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ]);

    $response->assertSessionHasErrors();
    expect($response->status())->not->toBe(500);
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeFalse();
});
