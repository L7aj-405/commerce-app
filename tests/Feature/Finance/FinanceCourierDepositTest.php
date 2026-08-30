<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\FinanceAccount;
use App\Models\FinanceCourierDeposit;
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
 * @return array{0: User, 1: Store, 2: Organization, 3: User} owner, store, organization, courier
 */
function depositWorkspace(string $name = 'Deposit Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    $deliveryRole = $store->roles()->where('name', 'Delivery agent')->first();
    $courier = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $courier);
    if ($deliveryRole !== null) {
        StoreMember::create(['store_id' => $store->id, 'user_id' => $courier->id, 'role' => 'manager', 'store_role_id' => $deliveryRole->id, 'is_active' => true, 'joined_at' => now()]);
    }

    return [$owner, $store, $organization, $courier];
}

/**
 * A pending COD order that has actually been DELIVERED — the normal case
 * for "ready to deposit" in this test file. Pass `$fulfillmentStatus` to
 * exercise the not-yet-delivered rejection path instead.
 */
function depositPendingOrder(Store $store, Organization $organization, float $total = 1000.0, FulfillmentStatus $fulfillmentStatus = FulfillmentStatus::Delivered): Order
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'total' => $total, 'platform_data' => [],
        'fulfillment_status' => $fulfillmentStatus,
    ]);
    app(FinanceOrderTransactionService::class)->syncOrderFinancials($order);

    return $order;
}

it('closes selected receivables and creates one cod_collected transaction once confirmed', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 400);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 400, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $deposit = FinanceCourierDeposit::where('organization_id', $organization->id)->firstOrFail();
    expect($deposit->status->value)->toBe('draft');

    // Nothing posted yet, order still pending.
    $pending = app(FinanceOrderTransactionService::class)->pendingCodOrderIds($organization->id);
    expect($pending)->toContain($order->id);

    $this->actingAs($owner)->post("/dashboard/finance/courier-deposits/{$deposit->id}/confirm")->assertRedirect();
    $deposit->refresh();
    expect($deposit->status->value)->toBe('confirmed');

    $pending = app(FinanceOrderTransactionService::class)->pendingCodOrderIds($organization->id);
    expect($pending)->not->toContain($order->id);

    $closing = FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_cleared_by_courier')->firstOrFail();
    expect($closing->direction->value)->toBe('neutral');

    $collected = FinanceTransaction::where('source_type', FinanceCourierDeposit::class)->where('source_id', $deposit->id)->where('type', 'cod_collected')->firstOrFail();
    expect($collected->direction->value)->toBe('in')
        ->and((float) $collected->amount)->toBe(400.0)
        ->and($collected->account_id)->toBe($cash->id);
});

it('records a clearly labelled variance transaction when cash received differs from expected', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 500);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 470, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ]);
    $deposit = FinanceCourierDeposit::where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/courier-deposits/{$deposit->id}/confirm");

    $collected = FinanceTransaction::where('source_type', FinanceCourierDeposit::class)->where('source_id', $deposit->id)->where('type', 'cod_collected')->firstOrFail();
    expect((float) $collected->amount)->toBe(470.0); // actual cash only — never the expected 500

    $variance = FinanceTransaction::where('source_type', FinanceCourierDeposit::class)->where('source_id', $deposit->id)->where('type', 'cod_courier_variance')->firstOrFail();
    expect($variance->direction->value)->toBe('neutral')
        ->and((float) $variance->amount)->toBe(30.0)
        ->and($variance->description)->toContain('shortage');
});

it('is idempotent when the same deposit is confirmed twice', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 250);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 250, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ]);
    $deposit = FinanceCourierDeposit::where('organization_id', $organization->id)->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/courier-deposits/{$deposit->id}/confirm");
    $this->actingAs($owner)->post("/dashboard/finance/courier-deposits/{$deposit->id}/confirm");
    $this->actingAs($owner)->post("/dashboard/finance/courier-deposits/{$deposit->id}/confirm");

    expect(FinanceTransaction::where('source_type', FinanceCourierDeposit::class)->where('source_id', $deposit->id)->where('type', 'cod_collected')->count())->toBe(1);
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_cleared_by_courier')->count())->toBe(1);
});

it('rejects a deposit that includes another organization\'s order', function (): void {
    [$ownerA, $storeA, $orgA, $courierA] = depositWorkspace('Deposit Reject A');
    [, $storeB, $orgB] = depositWorkspace('Deposit Reject B');
    $orderB = depositPendingOrder($storeB, $orgB, 350);
    $cashA = FinanceAccount::where('organization_id', $orgA->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($ownerA)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courierA->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 350, 'account_id' => $cashA->id, 'order_ids' => [$orderB->id],
    ])->assertSessionHasErrors('order_ids');
});

it('rejects a deposit whose account belongs to another organization', function (): void {
    [$ownerA, $storeA, $orgA, $courierA] = depositWorkspace('Deposit Acct Reject A');
    [, , $orgB] = depositWorkspace('Deposit Acct Reject B');
    $orderA = depositPendingOrder($storeA, $orgA, 220);
    $cashB = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($ownerA)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courierA->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 220, 'account_id' => $cashB->id, 'order_ids' => [$orderA->id],
    ])->assertSessionHasErrors('account_id');
});

it('denies a staff member without finance.manage_cod_settlements from creating or confirming a deposit', function (): void {
    [, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 200);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $limitedRole = StoreRole::create(['store_id' => $store->id, 'name' => 'Finance Viewer 3', 'permissions' => ['finance.view'], 'is_system' => false]);
    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create(['store_id' => $store->id, 'user_id' => $staff->id, 'role' => 'manager', 'store_role_id' => $limitedRole->id, 'is_active' => true, 'joined_at' => now()]);

    $this->actingAs($staff)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 200, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ])->assertForbidden();
});

it('rejects a confirmed-but-not-delivered COD order from being included in a courier deposit', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 300, FulfillmentStatus::Confirmed);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 300, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ])->assertSessionHasErrors('order_ids');

    expect(FinanceCourierDeposit::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('rejects a COD order that is still picking/packing/out for delivery from being included in a courier deposit', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    foreach ([FulfillmentStatus::Picking, FulfillmentStatus::Packing, FulfillmentStatus::ReadyForDelivery] as $status) {
        $order = depositPendingOrder($store, $organization, 100, $status);

        $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
            'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
            'cash_received' => 100, 'account_id' => $cash->id, 'order_ids' => [$order->id],
        ])->assertSessionHasErrors('order_ids');
    }

    expect(FinanceCourierDeposit::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('allows a delivered internal-courier COD order into a courier deposit', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 250, FulfillmentStatus::Delivered);
    \App\Models\OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-DEP-1', 'carrier_type' => \App\Models\OrderShipment::CARRIER_INTERNAL, 'agent_id' => $courier->id,
        'status' => \App\Models\OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total,
    ]);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 250, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(FinanceCourierDeposit::where('organization_id', $organization->id)->exists())->toBeTrue();
});

it('rejects a deposit for an order assigned to a different internal courier', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $otherCourier = User::factory()->create(['onboarding_completed_at' => now()]);
    $order = depositPendingOrder($store, $organization, 250, FulfillmentStatus::Delivered);
    \App\Models\OrderShipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'reference' => 'DISP-DEP-2', 'carrier_type' => \App\Models\OrderShipment::CARRIER_INTERNAL, 'agent_id' => $otherCourier->id,
        'status' => \App\Models\OrderShipment::STATUS_DELIVERED, 'cod_amount' => $order->total,
    ]);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 250, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ])->assertSessionHasErrors('order_ids');

    expect(FinanceCourierDeposit::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('rejects a forged courier deposit request for a non-delivered order even when the order is a genuine pending COD receivable', function (): void {
    [$owner, $store, $organization, $courier] = depositWorkspace();
    $order = depositPendingOrder($store, $organization, 888, FulfillmentStatus::Pending);
    $cash = FinanceAccount::where('organization_id', $organization->id)->where('type', 'cash')->firstOrFail();

    $response = $this->actingAs($owner)->post('/dashboard/finance/courier-deposits', [
        'courier_id' => $courier->id, 'deposit_date' => now()->toDateString(),
        'cash_received' => 888, 'account_id' => $cash->id, 'order_ids' => [$order->id],
    ]);

    $response->assertSessionHasErrors('order_ids');
    expect($response->status())->not->toBe(500);
    expect(FinanceTransaction::where('source_type', Order::class)->where('source_id', $order->id)->where('type', 'cod_cleared_by_courier')->exists())->toBeFalse();
    expect(FinanceCourierDeposit::where('organization_id', $organization->id)->exists())->toBeFalse();
});
