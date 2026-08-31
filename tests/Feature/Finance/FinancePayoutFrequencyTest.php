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
use Carbon\CarbonImmutable;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: DeliveryProvider}
 */
function fpfWorkspace(string $name = 'FPF Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();

    return [$owner, $store, $organization, $provider];
}

function fpfSettings(Organization $organization, DeliveryProvider $provider, string $frequency, int $delayDays = 0): DeliveryProviderFinanceSetting
{
    return DeliveryProviderFinanceSetting::create([
        'organization_id' => $organization->id, 'delivery_provider_id' => $provider->id,
        'default_delivery_fee' => 30, 'payout_frequency' => $frequency, 'payout_delay_days' => $delayDays,
    ]);
}

/** A delivered, external-carrier COD order with a computed fee snapshot, delivered at a specific moment. */
function fpfDeliveredOrder(Store $store, Organization $organization, CarbonImmutable $deliveredAt, float $total = 500.0): Order
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
        'city_name' => 'Casablanca', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Casablanca',
        'delivered_at' => $deliveredAt,
        'expected_delivery_fee' => 30, 'expected_cod_fee' => 0, 'expected_total_carrier_fee' => 30,
        'fee_source' => 'provider_default', 'fee_calculated_at' => now(),
    ]);

    return $order->fresh();
}

/*
|--------------------------------------------------------------------------
| Settings accept the two new frequencies
|--------------------------------------------------------------------------
*/

it('accepts daily as a provider payout frequency', function (): void {
    [$owner, , $organization, $provider] = fpfWorkspace();

    $this->actingAs($owner)->patch("/dashboard/finance/delivery-providers/{$provider->id}", [
        'default_delivery_fee' => 30, 'payout_frequency' => 'daily', 'payout_delay_days' => 0,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settings = DeliveryProviderFinanceSetting::where('organization_id', $organization->id)->firstOrFail();
    expect($settings->payout_frequency->value)->toBe('daily');
});

it('accepts instant as a provider payout frequency', function (): void {
    [$owner, , $organization, $provider] = fpfWorkspace();

    $this->actingAs($owner)->patch("/dashboard/finance/delivery-providers/{$provider->id}", [
        'default_delivery_fee' => 30, 'payout_frequency' => 'instant', 'payout_delay_days' => 0,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settings = DeliveryProviderFinanceSetting::where('organization_id', $organization->id)->firstOrFail();
    expect($settings->payout_frequency->value)->toBe('instant');
});

/*
|--------------------------------------------------------------------------
| Daily
|--------------------------------------------------------------------------
*/

it('groups daily-payout orders delivered on the same calendar day into one period, and a different day into another', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'daily', 2); // delay > 0 so "today" doesn't auto-flip to ready_to_verify

    $today = CarbonImmutable::now();
    fpfDeliveredOrder($store, $organization, $today->setTime(9, 0), 400);
    fpfDeliveredOrder($store, $organization, $today->setTime(17, 0), 600);
    fpfDeliveredOrder($store, $organization, $today->subDay()->setTime(12, 0), 100);

    $periods = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization);

    expect($periods)->toHaveCount(2);

    $todayPeriod = $periods->firstWhere('period_start', $today->toDateString());
    expect($todayPeriod['delivered_orders_count'])->toBe(2)
        ->and($todayPeriod['gross_cod'])->toBe(1000.0)
        ->and($todayPeriod['period_start'])->toBe($todayPeriod['period_end']);

    $yesterdayPeriod = $periods->firstWhere('period_start', $today->subDay()->toDateString());
    expect($yesterdayPeriod['delivered_orders_count'])->toBe(1)
        ->and($yesterdayPeriod['gross_cod'])->toBe(100.0);
});

it('daily payout with zero delay is ready to verify immediately, the same day it was delivered', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'daily', 0);
    fpfDeliveredOrder($store, $organization, CarbonImmutable::now());

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    expect($period['status'])->toBe('ready_to_verify')
        ->and($period['payout_date'])->toBe($period['period_end']);
});

it('daily payout with a delay stays accumulating until the delay has passed, then becomes ready to verify', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'daily', 2);
    $deliveredAt = CarbonImmutable::now();
    fpfDeliveredOrder($store, $organization, $deliveredAt);

    // Same day as delivery — still waiting on the configured delay.
    $sameDay = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
    expect($sameDay['status'])->toBe('accumulating')
        ->and($sameDay['payout_date'])->toBe($deliveredAt->startOfDay()->addDays(2)->toDateString());

    // Jump forward past the payout date.
    CarbonImmutable::setTestNow($deliveredAt->addDays(3));

    try {
        $later = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();
        expect($later['status'])->toBe('overdue');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| Instant
|--------------------------------------------------------------------------
*/

it('instant payout makes a delivered external COD order ready to verify immediately', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'instant', 0);
    fpfDeliveredOrder($store, $organization, CarbonImmutable::now());

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    expect($period)->not->toBeNull()
        ->and($period['status'])->toBe('ready_to_verify')
        ->and($period['payout_frequency'])->toBe('instant');
});

it('instant payout groups every delivered order for the same provider/day into one period, not one per order', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'instant', 0);
    $now = CarbonImmutable::now();
    fpfDeliveredOrder($store, $organization, $now, 300);
    fpfDeliveredOrder($store, $organization, $now, 700);

    $periods = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization);

    expect($periods)->toHaveCount(1);
    expect($periods->first()['delivered_orders_count'])->toBe(2)
        ->and($periods->first()['gross_cod'])->toBe(1000.0);
});

it('instant payout never creates a finance transaction just by appearing in a period', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'instant', 0);
    $order = fpfDeliveredOrder($store, $organization, CarbonImmutable::now());

    app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization);

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->whereIn('type', \App\Enums\FinanceTransactionType::codClosingTypes())->exists())->toBeFalse();
});

it('reconciling an instant payout period creates exactly one cash-in transaction, only after verification', function (): void {
    [$owner, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'instant', 0);
    fpfDeliveredOrder($store, $organization, CarbonImmutable::now(), 800);
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

/*
|--------------------------------------------------------------------------
| Existing frequencies + shared guardrails stay unchanged
|--------------------------------------------------------------------------
*/

it('leaves weekly payout behavior unchanged: accumulating until the period closes', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'weekly', 2);
    fpfDeliveredOrder($store, $organization, CarbonImmutable::now());

    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    expect($period['status'])->toBe('accumulating')
        ->and($period['period_start'])->not->toBe($period['period_end']);
});

it('never groups a non-delivered COD order into an instant provider\'s payout period', function (): void {
    [, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'instant', 0);
    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'fulfillment_status' => FulfillmentStatus::Confirmed]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);
    Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Casablanca',
    ]);

    expect(app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization))->toHaveCount(0);
});

it('never lets an instant-settled COD order be collected again manually', function (): void {
    [$owner, $store, $organization, $provider] = fpfWorkspace();
    fpfSettings($organization, $provider, 'instant', 0);
    $order = fpfDeliveredOrder($store, $organization, CarbonImmutable::now(), 500);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
    $period = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($organization)->first();

    app(FinanceCodSettlementService::class)->verifyProviderPeriod($organization, $owner, [
        'delivery_provider_id' => $provider->id,
        'actual_received_amount' => $period['expected_net'],
        'account_id' => $bank->id,
    ], $period['order_ids']->all());

    $this->actingAs($owner)->post("/dashboard/finance/cod-receivables/{$order->id}/mark-collected", [
        'account_id' => $bank->id, 'amount_collected' => 500, 'collected_at' => now()->toDateString(),
    ])->assertSessionHasErrors();

    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_collected')->exists())->toBeFalse();
});

it('rejects an instant-frequency provider settings update whose bank account belongs to another organization', function (): void {
    [$ownerA, , , $providerA] = fpfWorkspace('FPF Cross A');
    [, , $orgB] = fpfWorkspace('FPF Cross B');
    $bankB = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($ownerA)->patch("/dashboard/finance/delivery-providers/{$providerA->id}", [
        'payout_frequency' => 'instant', 'default_bank_account_id' => $bankB->id,
    ])->assertSessionHasErrors('default_bank_account_id');
});

it('never lets an instant payout period from another organization be verified', function (): void {
    [$ownerA] = fpfWorkspace('FPF Verify Cross A');
    [, $storeB, $orgB, $providerB] = fpfWorkspace('FPF Verify Cross B');
    fpfSettings($orgB, $providerB, 'instant', 0);
    fpfDeliveredOrder($storeB, $orgB, CarbonImmutable::now(), 500);
    $bankB = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'bank')->firstOrFail();
    $periodB = app(FinanceCodPayoutPeriodService::class)->pendingPeriods($orgB)->first();

    $this->actingAs($ownerA)->post('/dashboard/finance/cod-settlements/verify-period', [
        'delivery_provider_id' => $providerB->id,
        'order_ids' => $periodB['order_ids']->all(),
        'actual_received_amount' => $periodB['expected_net'],
        'account_id' => $bankB->id,
    ])->assertSessionHasErrors();

    expect(FinanceCodSettlement::where('organization_id', $orgB->id)->exists())->toBeFalse();
});
