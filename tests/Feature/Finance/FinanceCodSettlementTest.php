<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\FinanceAccount;
use App\Models\FinanceCodSettlement;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Organization;
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
function settlementWorkspace(string $name = 'Settlement Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    return [$owner, $store, $organization];
}

/**
 * A pending COD order that has actually been DELIVERED — the normal case
 * for "ready to settle" in this test file. Pass `$fulfillmentStatus` to
 * exercise the not-yet-delivered rejection path instead.
 */
function settlementPendingOrder(Store $store, Organization $organization, float $total = 1000.0, FulfillmentStatus $fulfillmentStatus = FulfillmentStatus::Delivered): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'total' => $total, 'platform_data' => [],
        'fulfillment_status' => $fulfillmentStatus,
    ]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    return $order;
}

it('creates an external COD settlement as a draft without touching the ledger yet', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'carrier_name' => 'Sendit',
        'settlement_date' => now()->toDateString(),
        'delivery_fees' => 50,
        'account_id' => $bank->id,
        'order_ids' => [$order->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();
    expect($settlement->status->value)->toBe('draft')
        ->and((float) $settlement->gross_cod_amount)->toBe(1000.0)
        ->and((float) $settlement->net_received)->toBe(950.0);

    // Nothing posted to the ledger yet, and the order is still pending.
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_settled_external')->exists())->toBeFalse();
    $pending = app(FinanceOrderTransactionService::class)->pendingCodOrderIds($organization->id);
    expect($pending)->toContain($order->id);
});

it('closes selected receivables and books the net amount once settled, without double-counting cash', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 1000);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'carrier_name' => 'Sendit', 'settlement_date' => now()->toDateString(),
        'delivery_fees' => 50, 'adjustments' => 0, 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ]);
    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/cod-settlements/{$settlement->id}/settle")->assertRedirect();

    $settlement->refresh();
    expect($settlement->status->value)->toBe('settled');

    // The receivable is closed (excluded from pending).
    $pending = app(FinanceOrderTransactionService::class)->pendingCodOrderIds($organization->id);
    expect($pending)->not->toContain($order->id);

    // Gross closing fact per order — Neutral, no cash effect.
    $closing = FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_settled_external')->firstOrFail();
    expect($closing->direction->value)->toBe('neutral')->and((float) $closing->amount)->toBe(1000.0);

    // Exactly one aggregate cash entry, for the NET amount, against the chosen account.
    $received = FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->firstOrFail();
    expect($received->direction->value)->toBe('in')
        ->and((float) $received->amount)->toBe(950.0)
        ->and($received->account_id)->toBe($bank->id);

    // Total cash actually recorded "in" for this org is the NET amount only —
    // the gross 1000 never hits an account balance, so cash is never double-counted.
    $totalIn = (float) FinanceTransaction::where('organization_id', $organization->id)->where('direction', 'in')->sum('amount');
    expect($totalIn)->toBe(950.0);
});

it('is idempotent when the same settlement is confirmed twice', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 500);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ]);
    $settlement = FinanceCodSettlement::where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/cod-settlements/{$settlement->id}/settle");
    $this->actingAs($owner)->post("/dashboard/finance/cod-settlements/{$settlement->id}/settle");
    $this->actingAs($owner)->post("/dashboard/finance/cod-settlements/{$settlement->id}/settle");

    expect(FinanceTransaction::where('source_type', FinanceCodSettlement::class)->where('source_id', $settlement->id)->where('type', 'cod_settlement_received')->count())->toBe(1);
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_settled_external')->count())->toBe(1);
});

it('rejects a settlement that includes another organization\'s order', function (): void {
    [$ownerA, $storeA, $orgA] = settlementWorkspace('Settlement Reject A');
    [, $storeB, $orgB] = settlementWorkspace('Settlement Reject B');
    $orderB = settlementPendingOrder($storeB, $orgB, 400);
    $bankA = FinanceAccount::where('organization_id', $orgA->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($ownerA)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bankA->id, 'order_ids' => [$orderB->id],
    ])->assertSessionHasErrors('order_ids');
});

it('rejects a settlement whose account belongs to another organization', function (): void {
    [$ownerA, $storeA, $orgA] = settlementWorkspace('Settlement Acct Reject A');
    [, , $orgB] = settlementWorkspace('Settlement Acct Reject B');
    $orderA = settlementPendingOrder($storeA, $orgA, 300);
    $bankB = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($ownerA)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bankB->id, 'order_ids' => [$orderA->id],
    ])->assertSessionHasErrors('account_id');
});

it('denies a staff member without finance.manage_cod_settlements from creating or settling', function (): void {
    [, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 200);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $limitedRole = StoreRole::create(['store_id' => $store->id, 'name' => 'Finance Viewer 2', 'permissions' => ['finance.view'], 'is_system' => false]);
    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create(['store_id' => $store->id, 'user_id' => $staff->id, 'role' => 'manager', 'store_role_id' => $limitedRole->id, 'is_active' => true, 'joined_at' => now()]);

    $this->actingAs($staff)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ])->assertForbidden();
});

it('serializes settlement dates as date-only (no raw ISO timestamp) on the COD receivables page', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 150);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => '2026-08-20', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'account_id' => $bank->id, 'order_ids' => [$order->id],
    ]);

    $response = $this->actingAs($owner)->get('/dashboard/finance/cod-receivables')->assertOk();
    $settlement = collect($response->viewData('page')['props']['settlements'])->first();

    expect($settlement['settlement_date'])->toBe('2026-08-20')
        ->and($settlement['period_start'])->toBe('2026-08-01')
        ->and($settlement['period_end'])->toBe('2026-08-31');
});

it('rejects a confirmed-but-not-delivered COD order from being included in an external settlement', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 400, FulfillmentStatus::Confirmed);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ])->assertSessionHasErrors('order_ids');

    expect(FinanceCodSettlement::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('rejects a COD order that is still picking/packing/out for delivery from being included in an external settlement', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    foreach ([FulfillmentStatus::Picking, FulfillmentStatus::Packing, FulfillmentStatus::ReadyForDelivery] as $status) {
        $order = settlementPendingOrder($store, $organization, 100, $status);

        $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();
        $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
            'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
        ])->assertSessionHasErrors('order_ids');
    }

    expect(FinanceCodSettlement::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('allows a delivered external-carrier COD order into an external settlement', function (): void {
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 600, FulfillmentStatus::Delivered);
    \App\Models\Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => \App\Models\Shipment::STATUS_DELIVERED,
        'receiver_name' => 'Test Receiver', 'phone' => '0600000000', 'address' => 'Casablanca',
    ]);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(FinanceCodSettlement::where('organization_id', $organization->id)->exists())->toBeTrue();
});

it('rejects a forged settlement request for a non-delivered order even when the order is a genuine pending COD receivable', function (): void {
    // Simulates a client bypassing the UI's disabled checkbox and posting
    // the order id directly — the backend must still refuse it.
    [$owner, $store, $organization] = settlementWorkspace();
    $order = settlementPendingOrder($store, $organization, 777, FulfillmentStatus::Pending);
    $bank = FinanceAccount::where('organization_id', $organization->id)->where('type', 'bank')->firstOrFail();

    $response = $this->actingAs($owner)->post('/dashboard/finance/cod-settlements', [
        'settlement_date' => now()->toDateString(), 'account_id' => $bank->id, 'order_ids' => [$order->id],
    ]);

    $response->assertSessionHasErrors('order_ids');
    expect($response->status())->not->toBe(500);
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_settled_external')->exists())->toBeFalse();
    expect(FinanceCodSettlement::where('organization_id', $organization->id)->exists())->toBeFalse();
});
