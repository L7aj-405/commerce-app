<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\DeliveryProvider;
use App\Models\DeliveryProviderFinanceSetting;
use App\Models\FinanceAccount;
use App\Models\FinanceCodSettlement;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceCodPayoutPeriodService;
use App\Services\Finance\FinanceCodSettlementService;
use App\Services\Finance\FinanceOrderTransactionService;
use App\Services\Orders\DispatchService;
use App\Services\OrganizationProvisioner;

/**
 * "View settlement period should open the instant period directly" — the
 * bug was that DispatchService::markDelivered() (the Delivery Board's own
 * "Mark delivered" action — the choke point BOTH a manual dispatcher click
 * AND ShipmentTrackingService::apply()'s real-tracking close-out go
 * through) never touched the rich Shipment record at all, so an order
 * genuinely assigned to Ozon via the real integration still had no
 * delivered_at/fee snapshot for FinanceCodPayoutPeriodService to find —
 * exactly what the diagnostic modal correctly (but confusingly, for an
 * otherwise-valid order) reported. Fixed by calling
 * FinanceDeliveryProviderFeeCalculator::prepareShipmentForSettlement() from
 * that SAME choke point.
 *
 * @return array{0: User, 1: Store, 2: Organization, 3: DeliveryProvider}
 */
function fcvpWorkspace(string $name = 'FCVP Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();

    return [$owner, $store, $organization, $provider];
}

/**
 * Mirrors OzonShipmentService::sendOrder()'s own real linking sequence
 * (Shipment created, sent to carrier, then DispatchService::assign() bridges
 * it into the dispatch board and the two rows are cross-linked via
 * order_shipment_id) — the genuine "assigned to Ozon" state, BEFORE
 * delivery. Deliberately does NOT set delivered_at/fee snapshot: that's the
 * whole point of the fix under test, it must appear on its own once the
 * order is marked delivered through the normal Delivery Board action.
 */
function fcvpAssignToOzon(Order $order, User $actor): Shipment
{
    $shipment = Shipment::create([
        'store_id' => $order->store_id, 'organization_id' => $order->organization_id,
        'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'receiver_name' => $order->customer_name ?? 'Test', 'phone' => $order->customer_phone ?? '0600000000',
        'address' => 'Casablanca', 'city_name' => 'Casablanca', 'cod_amount' => $order->total, 'sent_at' => now(),
    ]);

    $orderShipment = app(DispatchService::class)->assign($order, [
        'carrier_type' => 'courier', 'carrier_name' => 'Ozon Express', 'tracking_number' => 'OZN-TEST-1',
    ], $actor);

    $shipment->update(['order_shipment_id' => $orderShipment->id]);

    return $shipment->fresh();
}

it('delivered Ozon COD with payout_frequency instant returns settlement_period, not diagnostics, once marked delivered through the real Delivery Board action', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ]);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    $shipment = fcvpAssignToOzon($order, $owner);

    expect($shipment->status)->not->toBe(Shipment::STATUS_DELIVERED)
        ->and($shipment->fee_calculated_at)->toBeNull();

    // The actual bug-triggering action: the Delivery Board's own "Mark
    // delivered" — not a direct DB edit, not the recalculate tool.
    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    $shipment->refresh();
    expect($shipment->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($shipment->delivered_at)->not->toBeNull()
        ->and($shipment->fee_calculated_at)->not->toBeNull()
        ->and((float) $shipment->expected_delivery_fee)->toBe(35.0);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['collectability_status'])->toBe('delivered_awaiting_provider_payout')
        ->and($row['settlement_diagnostics'])->toBeNull()
        ->and($row['settlement_period'])->not->toBeNull()
        ->and($row['settlement_period']['provider_code'])->toBe('ozon');

    $period = collect($response->viewData('page')['props']['providerPeriods'])->firstWhere('provider_code', 'ozon');
    expect($period)->not->toBeNull()
        ->and($period['status'])->toBe('ready_to_verify')
        ->and($period['payout_frequency'])->toBe('instant')
        ->and($period['order_ids'])->toContain($order->id)
        ->and($period['gross_cod'])->toBe(500.0)
        ->and($period['expected_fees'])->toBe(35.0);

    // No cash, no closed receivable — delivering it (however it was marked)
    // never bypasses reconciliation.
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->whereIn('type', \App\Enums\FinanceTransactionType::codClosingTypes())->exists())->toBeFalse();
});

it('never requires the local-only recalculate tool for a normal instant delivered order', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    fcvpAssignToOzon($order, $owner);

    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    // Recalculate would be a genuine no-op here — proving it was never
    // NEEDED for this order, only ever a fallback.
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    expect($period)->not->toBeNull()->and($period['order_ids'])->toContain($order->id);
});

it('verifying the bank transfer for a normally-delivered instant order creates exactly one cash-in transaction', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    fcvpAssignToOzon($order, $owner);
    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => $period['expected_net'],
        'account_id' => $bank->id,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    expect($settlement->status->value)->toBe('settled');

    $cashIn = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->get();
    expect($cashIn)->toHaveCount(1)->and((float) $cashIn->first()->amount)->toBe($period['expected_net']);
});

it('shows the cash-in transaction on the Transactions page after verifying an instant bank transfer into the provider default bank account', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    $bank = FinanceAccount::create([
        'organization_id' => $organization->id, 'name' => 'CIH BANK', 'type' => 'bank', 'is_active' => true,
    ]);
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
        'default_bank_account_id' => $bank->id,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 299, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    fcvpAssignToOzon($order, $owner);
    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    expect($period['default_bank_account_id'])->toBe($bank->id)
        ->and($period['gross_cod'])->toBe(299.0)
        ->and($period['expected_fees'])->toBe(35.0)
        ->and($period['expected_net'])->toBe(264.0);

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => 264,
        'account_id' => $bank->id,
        'received_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    $cashIn = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->first();
    expect($cashIn)->not->toBeNull()
        ->and((float) $cashIn->amount)->toBe(264.0)
        ->and($cashIn->direction->value)->toBe('in')
        ->and($cashIn->account_id)->toBe($bank->id);

    // The actual UI symptom under investigation: does the transaction show
    // up on the Finance > Transactions page a real accountant lands on
    // after clicking "Verify bank transfer" — not just in the DB directly.
    $response = $this->actingAs($owner)->get('/dashboard/finance/transactions')->assertOk();
    $rows = collect($response->viewData('page')['props']['transactions']['data']);
    $row = $rows->firstWhere('id', $cashIn->id);

    expect($row)->not->toBeNull('The cash-in transaction is missing from the Transactions page rows entirely.')
        ->and((float) $row['amount'])->toBe(264.0)
        ->and($row['direction'])->toBe('in')
        ->and($row['type'])->toBe('cod_settlement_received')
        ->and($row['account']['name'] ?? null)->toBe('CIH BANK');
});

it('records occurred_at as the real verification moment when verifying today, not midnight', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    $bank = FinanceAccount::create([
        'organization_id' => $organization->id, 'name' => 'CIH BANK', 'type' => 'bank', 'is_active' => true,
    ]);
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
        'default_bank_account_id' => $bank->id,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 299, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    fcvpAssignToOzon($order, $owner);
    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    // occurred_at's column has second (not microsecond) precision, so a
    // tight [now(), now()] window is flaky by a fraction of a second —
    // pad both ends by a second to absorb that truncation.
    $before = now()->subSecond();
    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => 264,
        'account_id' => $bank->id,
        'received_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors()->assertRedirect();
    $after = now()->addSecond();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    $cashIn = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->firstOrFail();

    expect($cashIn->occurred_at->between($before, $after))->toBeTrue()
        ->and($cashIn->occurred_at->format('H:i:s'))->not->toBe('00:00:00');
});

it('keeps midnight for a deliberately backdated settlement date — no real time to use', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    $bank = FinanceAccount::create([
        'organization_id' => $organization->id, 'name' => 'CIH BANK', 'type' => 'bank', 'is_active' => true,
    ]);
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
        'default_bank_account_id' => $bank->id,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 299, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    fcvpAssignToOzon($order, $owner);
    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    $yesterday = now()->subDay()->toDateString();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => 264,
        'account_id' => $bank->id,
        'received_at' => $yesterday,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    $cashIn = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->firstOrFail();

    expect($cashIn->occurred_at->toDateString())->toBe($yesterday)
        ->and($cashIn->occurred_at->format('H:i:s'))->toBe('00:00:00');
});

it('still shows a diagnostic when the Shipment exists but was never actually marked delivered', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ]);
    // fulfillment_status flipped straight to Delivered WITHOUT going through
    // DispatchService::markDelivered() or ShipmentTrackingService::apply()
    // at all — a genuinely incomplete state no normal UI action produces,
    // but must still be diagnosed rather than silently misreported.
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'receiver_name' => 'Test', 'phone' => '0600000000', 'address' => 'Casablanca',
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['settlement_period'])->toBeNull()
        ->and($row['settlement_diagnostics'])->toContain('Shipment has no delivered_at date.')
        ->and($row['settlement_diagnostics'])->toContain('Order is not delivered according to the shipment record.');
});

it('still shows a diagnostic when no external shipment exists at all', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::Delivered]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    \App\Models\OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-FCVP-1', 'carrier_type' => \App\Models\OrderShipment::CARRIER_COURIER, 'carrier_name' => 'Unknown Local Courier',
        'status' => \App\Models\OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total, 'delivered_at' => now(),
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $row = collect($response->viewData('page')['props']['orders'])->firstWhere('order_number', $order->order_number);

    expect($row['settlement_period'])->toBeNull()
        ->and($row['settlement_diagnostics'])->toContain('No external shipment found for this order.');
});

it('leaves weekly-frequency delivered orders behaving exactly as before this fix', function (): void {
    [$owner, $store, $organization, $provider] = fcvpWorkspace();
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 30, 'payout_frequency' => 'weekly', 'payout_delay_days' => 2,
    ]);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    fcvpAssignToOzon($order, $owner);

    app(DispatchService::class)->markDelivered($order->fresh()->orderShipment, $owner);

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    expect($period)->not->toBeNull()
        ->and($period['status'])->toBe('accumulating')
        ->and($period['payout_frequency'])->toBe('weekly');
});

it('never lets a cross-organization order be focused by another org\'s COD Receivables page', function (): void {
    [$ownerA] = fcvpWorkspace('FCVP Cross A');
    [$ownerB, $storeB, $orgB, $providerB] = fcvpWorkspace('FCVP Cross B');
    DeliveryProviderFinanceSetting::create([
        'organization_id' => $orgB->id, 'delivery_provider_id' => $providerB->id,
        'default_delivery_fee' => 35, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ]);
    $orderB = Order::factory()->create(['store_id' => $storeB->id, 'organization_id' => $orgB->id, 'total' => 500, 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($orderB);
    fcvpAssignToOzon($orderB, $ownerB);
    app(DispatchService::class)->markDelivered($orderB->fresh()->orderShipment, $ownerB);

    $response = $this->actingAs($ownerA)->get('/dashboard/finance/cod-receivables')->assertOk();
    $orderIds = collect($response->viewData('page')['props']['orders'])->pluck('id');
    $periodOrderIds = collect($response->viewData('page')['props']['providerPeriods'])->flatMap(fn ($p) => $p['order_ids']);

    expect($orderIds)->not->toContain($orderB->id)
        ->and($periodOrderIds)->not->toContain($orderB->id);
});
