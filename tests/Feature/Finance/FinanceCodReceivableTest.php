<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\DeliveryProvider;
use App\Models\DeliveryProviderFinanceSetting;
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
use App\Services\Finance\FinanceCodPayoutPeriodService;
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

/*
|--------------------------------------------------------------------------
| Direct "Mark collected" bypass fix — delivered COD carried by an
| external provider or internal courier must go through ITS OWN workflow
| (External Settlements / Courier Deposits), never the ad-hoc action.
|--------------------------------------------------------------------------
*/

it('shows a delivered COD order assigned to an external carrier as awaiting provider payout, not directly collectable', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD External Direct Block');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca', 'delivered_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    // Still eligible for BATCH inclusion in an external settlement...
    expect($row['is_collectable'])->toBeTrue()
        ->and($row['collectability_status'])->toBe('delivered_awaiting_provider_payout')
        ->and($row['external_carrier'])->toBe('Ozon Express')
        // ...but the ad-hoc single-order action is blocked.
        ->and($row['is_directly_collectable'])->toBeFalse()
        ->and($row['label'])->toContain('Ozon Express')
        ->and($row['reason'])->toContain('External Settlements');
});

it('rejects a forged direct "mark collected" request for a delivered order assigned to an external carrier, without a 500', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD External Direct Reject');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca', 'delivered_at' => now(),
    ]);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $response = $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ]);

    $response->assertSessionHasErrors();
    expect($response->status())->not->toBe(500)
        ->and(session('errors')->get('order')[0])->toContain('external delivery provider')
        ->and(session('errors')->get('order')[0])->toContain('External Settlements');

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeFalse();
    // Never touched the receivable either.
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->whereIn('type', \App\Enums\FinanceTransactionType::codClosingTypes())->exists())->toBeFalse();
});

it('shows a delivered COD order assigned to an internal courier as awaiting courier deposit, not directly collectable', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Internal Direct Block');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $agent = User::factory()->create(['name' => 'Youssef the Driver', 'onboarding_completed_at' => now()]);
    OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-2', 'carrier_type' => OrderShipment::CARRIER_INTERNAL, 'agent_id' => $agent->id,
        'status' => OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total, 'delivered_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['is_collectable'])->toBeTrue()
        ->and($row['collectability_status'])->toBe('delivered_awaiting_courier_deposit')
        ->and($row['internal_courier'])->toBe('Youssef the Driver')
        ->and($row['is_directly_collectable'])->toBeFalse();
});

it('rejects a forged direct "mark collected" request for a delivered order assigned to an internal courier, without a 500', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Internal Direct Reject');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $agent = User::factory()->create(['onboarding_completed_at' => now()]);
    OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-3', 'carrier_type' => OrderShipment::CARRIER_INTERNAL, 'agent_id' => $agent->id,
        'status' => OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total, 'delivered_at' => now(),
    ]);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $response = $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ]);

    $response->assertSessionHasErrors();
    expect($response->status())->not->toBe(500)
        ->and(session('errors')->get('order')[0])->toContain('Courier Deposits');
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeFalse();
});

it('still lets a manual/direct COD delivery (no external provider, no internal courier) be marked collected', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Manual Direct Still Works');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $listing = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($listing->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);
    expect($row['is_directly_collectable'])->toBeTrue();

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $cash->id, 'amount_collected' => (float) $order->total, 'collected_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeTrue();
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

/*
|--------------------------------------------------------------------------
| External Settlements empty-period fix — "View settlement period" must
| never lead to a silently empty tab: the row either already belongs to a
| live payout period (settlement_period), or the response says exactly why
| not (settlement_diagnostics) — see FinanceCodSettlementDiagnosticsService.
|--------------------------------------------------------------------------
*/

it('diagnoses "no external shipment found" when only order_shipments has delivery data, not the real Shipment record', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Diag No Shipment');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    // Exactly the repro: dispatched via the plain Delivery Board (free-text
    // courier name, order_shipments only) — NEVER through the real Ozon
    // integration, so no `shipments` row exists at all. Editing
    // order_shipments.delivered_at (as in the bug report) changes nothing
    // Finance reads from.
    OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-DIAG-1', 'carrier_type' => OrderShipment::CARRIER_COURIER, 'carrier_name' => 'Ozon Express',
        'status' => OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total, 'delivered_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['collectability_status'])->toBe('delivered_awaiting_provider_payout')
        ->and($row['settlement_period'])->toBeNull()
        ->and($row['settlement_diagnostics'])->not->toBeNull()
        ->and($row['settlement_diagnostics'])->toContain('No external shipment found for this order.');

    // The External settlements tab genuinely has nothing for this provider —
    // consistent with the diagnostic, not a silent mismatch.
    $providerPeriods = collect($response->viewData('page')['props']['providerPeriods']);
    expect($providerPeriods->flatMap(fn ($p) => $p['order_ids']))->not->toContain($order->id);
});

it('a delivered external shipment with a fee snapshot appears in its correct payout period', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Diag Full Setup');
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'weekly',
    ]);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 400, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca', 'delivered_at' => now(),
        'expected_delivery_fee' => 35, 'expected_cod_fee' => 0, 'expected_total_carrier_fee' => 35,
        'fee_source' => 'provider_default', 'fee_calculated_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['settlement_diagnostics'])->toBeNull()
        ->and($row['settlement_period'])->not->toBeNull()
        ->and($row['settlement_period']['provider_code'])->toBe('ozon');

    $period = collect($response->viewData('page')['props']['providerPeriods'])->firstWhere('provider_code', 'ozon');
    expect($period)->not->toBeNull()
        ->and($period['order_ids'])->toContain($order->id)
        ->and($period['gross_cod'])->toBe(400.0)
        ->and($period['expected_fees'])->toBe(35.0);
});

it('still includes an order with a delivered shipment but no fee snapshot in its period, flagged for manual review — never silently dropped', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Diag Missing Fee');
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'weekly',
    ]);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 400, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    // Delivered, provider known — but the fee snapshot was never computed
    // (e.g. the local tracking webhook never fired).
    Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca', 'delivered_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    // Safely included (never silently dropped)...
    expect($row['settlement_period'])->not->toBeNull();

    // ...but visibly flagged so the accountant knows to review/recalculate.
    $period = collect($response->viewData('page')['props']['providerPeriods'])->firstWhere('provider_code', 'ozon');
    expect($period['has_manual_required_fees'])->toBeTrue();
});

it('never leaves a "delivered — awaiting provider payout" row without either a period or a diagnostic reason', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Diag Never Empty');
    // No DeliveryProviderFinanceSetting at all — provider unconfigured.
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca', 'delivered_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $rows = collect($response->viewData('page')['props']['orders'])->where('collectability_status', 'delivered_awaiting_provider_payout');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['settlement_period'] !== null || (is_array($row['settlement_diagnostics']) && count($row['settlement_diagnostics']) > 0))->toBeTrue();
    }

    $row = $rows->firstWhere('order_number', $order->order_number);
    expect($row['settlement_diagnostics'])->toContain('No payout settings configured for this provider.');
});

it('recalculates settlement data locally: repairs a stale Shipment and computes its fee snapshot, without touching the ledger', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Recalc Repair');
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'weekly',
    ]);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    // A real Shipment exists but is stale — created/sent, never actually
    // marked delivered (ShipmentTrackingService::apply() never ran locally).
    $shipment = Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca',
    ]);
    $transactionCountBefore = FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->count();

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/recalculate-settlement")
        ->assertSessionHasNoErrors()->assertRedirect();

    $shipment->refresh();
    expect($shipment->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($shipment->delivered_at)->not->toBeNull()
        ->and($shipment->fee_calculated_at)->not->toBeNull()
        ->and((float) $shipment->expected_delivery_fee)->toBe(35.0);

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->firstWhere('provider_code', 'ozon');
    expect($period)->not->toBeNull()->and($period['order_ids'])->toContain($order->id);

    // Repairing the Shipment/fee snapshot must never touch the ledger — the
    // order's pre-existing sale/receivable facts (from syncOrderFinancials
    // above) are untouched, and nothing new was added.
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->count())->toBe($transactionCountBefore);
});

it('recalculates settlement data from order_shipments when no real Shipment exists, matching a known provider by name', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Recalc From OrderShipment');
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => DeliveryProvider::where('code', 'ozon')->value('id'),
        'default_delivery_fee' => 40, 'payout_frequency' => 'weekly',
    ]);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-RECALC-1', 'carrier_type' => OrderShipment::CARRIER_COURIER, 'carrier_name' => 'Ozon Express',
        'status' => OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total, 'delivered_at' => now(),
    ]);
    $transactionCountBefore = FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->count();

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/recalculate-settlement")
        ->assertSessionHasNoErrors()->assertRedirect();

    $shipment = Shipment::where('shippable_id', $order->id)->first();
    expect($shipment)->not->toBeNull()
        ->and($shipment->provider_code)->toBe('ozon')
        ->and($shipment->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($shipment->fee_calculated_at)->not->toBeNull();

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->count())->toBe($transactionCountBefore);
});

it('is unavailable outside local/testing environments', function (): void {
    [$owner, $store, $organization] = codWorkspace('COD Recalc Prod Guard');
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    // Flipping the detected environment also re-enables CSRF verification
    // (Illuminate\Foundation\Application::runningUnitTests() keys off
    // environment() === 'testing') — disable it explicitly so the request
    // actually reaches the route's own environment guard instead of
    // failing earlier with an unrelated 419.
    app()->detectEnvironment(fn () => 'production');

    try {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/recalculate-settlement")
            ->assertStatus(404);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('wires "View settlement period" to focus the matching period, and shows diagnostics instead of an empty tab otherwise', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Dashboard/Finance/CodReceivables/Index.jsx'));

    // Clicking the action never just blindly switches tabs — it checks
    // whether the order actually has a live period first.
    expect($source)->toContain('const viewSettlement = (order) => {')
        ->toContain('if (order.settlement_period)')
        ->toContain('setFocusPeriodKey(')
        ->toContain('setDiagnosingOrder(order)')
        // The matching period card gets visibly highlighted and scrolled to.
        ->toContain('highlighted={focusPeriodKey === key}')
        ->toContain('scrollIntoView(')
        // No period found -> a diagnostics modal, never a silent empty tab.
        ->toContain('<SettlementDiagnosticsModal');
});

it('never lets the recalculate tool reach or be inferred from another organization\'s order', function (): void {
    [$ownerA] = codWorkspace('COD Recalc Cross A');
    [, $storeB, $orgB] = codWorkspace('COD Recalc Cross B');
    $orderB = Order::factory()->create(['store_id' => $storeB->id, 'organization_id' => $orgB->id, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderB);

    $this->actingAs($ownerA)->post("/dashboard/finance/cod-receivables/{$orderB->id}/recalculate-settlement")
        ->assertStatus(404);
});
