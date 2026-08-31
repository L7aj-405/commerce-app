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
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: DeliveryProvider, 4: DeliveryProviderFinanceSetting}
 */
function fcsrWorkspace(string $name = 'FCSR Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();
    $settings = DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 30, 'payout_frequency' => 'weekly', 'payout_delay_days' => 2,
    ]);

    return [$owner, $store, $organization, $provider, $settings];
}

/** A delivered, external-carrier COD order WITH a computed fee snapshot already on its shipment. */
function fcsrDeliveredOrder(Store $store, Organization $organization, float $total = 1000.0, string $cityName = 'Casablanca'): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'total' => $total, 'platform_data' => [],
        'fulfillment_status' => FulfillmentStatus::Delivered,
    ]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'city_name' => $cityName, 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => $cityName,
        'delivered_at' => now(),
        'expected_delivery_fee' => 30, 'expected_cod_fee' => 0, 'expected_total_carrier_fee' => 30,
        'fee_source' => 'provider_default', 'fee_calculated_at' => now(),
    ]);

    return $order->fresh();
}

it('groups delivered orders into a payout period and computes totals from stored fee snapshots', function (): void {
    [, $store, $organization] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    fcsrDeliveredOrder($store, $organization, 500);

    $periods = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization);

    expect($periods)->toHaveCount(1);
    $period = $periods->first();
    expect($period['delivered_orders_count'])->toBe(2)
        ->and($period['gross_cod'])->toBe(1500.0)
        ->and($period['expected_fees'])->toBe(60.0)
        ->and($period['expected_net'])->toBe(1440.0)
        ->and($period['has_manual_required_fees'])->toBeFalse();
});

it('excludes a confirmed (not yet delivered) COD order from any payout period', function (): void {
    [, $store, $organization] = fcsrWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 300, 'platform_data' => [], 'fulfillment_status' => FulfillmentStatus::Confirmed]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create(['store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id, 'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER, 'city_name' => 'Casablanca', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Casablanca']);

    expect(app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization))->toHaveCount(0);
});

it('excludes an out-for-delivery COD order from any payout period', function (): void {
    [, $store, $organization] = fcsrWorkspace();
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 300, 'platform_data' => [], 'fulfillment_status' => FulfillmentStatus::ReadyForDelivery]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create(['store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id, 'provider_code' => 'ozon', 'status' => Shipment::STATUS_OUT_FOR_DELIVERY, 'city_name' => 'Casablanca', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Casablanca']);

    expect(app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization))->toHaveCount(0);
});

it('creates exactly one cash-in transaction when the reconciled amount matches expectations', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'period_start' => $period['period_start'], 'period_end' => $period['period_end'],
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => $period['expected_net'],
        'account_id' => $bank->id,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    expect($settlement->status->value)->toBe('settled')
        ->and((float) $settlement->variance_amount)->toBe(0.0);

    $received = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->get();
    expect($received)->toHaveCount(1)->and((float) $received->first()->amount)->toBe($period['expected_net']);

    expect(FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_variance')->count())->toBe(0);
});

it('records a variance transaction and marks the settlement partial when the actual amount is less than expected', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    $actual = $period['expected_net'] - 50;

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'period_start' => $period['period_start'], 'period_end' => $period['period_end'],
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => $actual,
        'account_id' => $bank->id,
        'notes' => 'Carrier deducted an extra penalty fee.',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    expect($settlement->status->value)->toBe('partial')
        ->and((float) $settlement->variance_amount)->toBe(-50.0);

    $variance = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_variance')->firstOrFail();
    expect((float) $variance->amount)->toBe(50.0)->and($variance->direction->value)->toBe('neutral');
});

it('flags the settlement disputed when the actual amount received is MORE than expected', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $provider->id,
        'period_start' => $period['period_start'], 'period_end' => $period['period_end'],
        'order_ids' => $period['order_ids']->all(),
        'actual_received_amount' => $period['expected_net'] + 20,
        'account_id' => $bank->id,
        'notes' => 'Unexplained overpayment.',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    expect($settlement->status->value)->toBe('disputed');
});

it('rejects a mismatched actual amount with no note explaining the variance', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    $settlement = app(FinanceCodSettlementService::class)->create($organization, $owner, [
        'delivery_provider_id' => $provider->id,
        'settlement_date' => now()->toDateString(),
        'account_id' => $bank->id,
    ], $period['order_ids']->all());

    $this->actingAs($owner)->post("/dashboard/finance/cod-settlements/{$settlement->id}/reconcile", [
        'actual_received_amount' => (float) $settlement->expected_net_amount - 50,
        // no notes
    ])->assertSessionHasErrors('notes');
});

it('never duplicates transactions when the same period settlement is reconciled twice', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    $service = app(FinanceCodSettlementService::class);
    $settlement = $service->verifyProviderPeriod($organization, $owner, [
        'delivery_provider_id' => $provider->id,
        'actual_received_amount' => $period['expected_net'],
        'account_id' => $bank->id,
    ], $period['order_ids']->all());

    // A second reconcile() call on the now-finalized settlement is a no-op —
    // isDraft() is false, exactly like the legacy settle()'s idempotency.
    $service->reconcile($settlement, ['actual_received_amount' => $period['expected_net']]);

    expect(FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->count())->toBe(1);
    expect(FinanceTransaction::where('source_type', Order::class)->where('type', 'cod_settled_external')->count())->toBe(1);
});

it('never lets a COD order already settled via an external carrier be collected again manually', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    $order = fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    app(FinanceCodSettlementService::class)->verifyProviderPeriod($organization, $owner, [
        'delivery_provider_id' => $provider->id,
        'actual_received_amount' => $period['expected_net'],
        'account_id' => $bank->id,
    ], $period['order_ids']->all());

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $bank->id, 'amount_collected' => 1000, 'collected_at' => now()->toDateString(),
    ])->assertSessionHasErrors();

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeFalse();
});

it('never counts a delivered external COD order as cash on the Monthly Statement until the provider payout is reconciled', function (): void {
    [$owner, $store, $organization, $provider] = fcsrWorkspace();
    fcsrDeliveredOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $statement = app(\App\Services\Finance\FinanceMonthlyStatementService::class)->forMonth(now()->format('Y-m'), null, $organization);

    // Delivered, still pending a provider payout — no bank transfer has
    // happened yet, so it must not appear as "collected" cash anywhere.
    expect($statement['cashflow']['collections']['amount'])->toBe(0.0)
        ->and($statement['external_cod']['actual_received'])->toBe(0.0)
        ->and($statement['external_cod']['pending_delivered_unpaid_cod'])->toBe(1000.0);

    // Now the accountant actually verifies the bank transfer...
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    app(FinanceCodSettlementService::class)->verifyProviderPeriod($organization, $owner, [
        'delivery_provider_id' => $provider->id,
        'actual_received_amount' => $period['expected_net'],
        'account_id' => $bank->id,
    ], $period['order_ids']->all());

    // ...and ONLY NOW does it count as real cash collected.
    $after = app(\App\Services\Finance\FinanceMonthlyStatementService::class)->forMonth(now()->format('Y-m'), null, $organization);
    expect($after['cashflow']['collections']['amount'])->toBe($period['expected_net'])
        ->and($after['external_cod']['actual_received'])->toBe($period['expected_net'])
        ->and($after['external_cod']['pending_delivered_unpaid_cod'])->toBe(0.0);
});
