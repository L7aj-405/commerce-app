<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\BonDeLivraison;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Metrics\OwnerDashboardMetricsService;
use App\Services\OrganizationProvisioner;

/**
 * The owner dashboard must show a WHOLE-BUSINESS view — POS + online
 * commerce combined — never POS-only. See OwnerDashboardMetricsService's
 * class docblock and its *_REVENUE_EXCLUDED_STATUSES / PENDING_DELIVERY_
 * STATUSES constants for the exact business rules these tests pin down.
 */

/** @return array{0: User, 1: Store} */
function ownerMetricsWorkspace(string $name = 'Owner Metrics Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ownerMetricsMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'cashier',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

function ownerMetricsPosOrder(Store $store, User $cashier, float $total, string $status = 'completed', ?\DateTimeInterface $createdAt = null): PosOrder
{
    $session = PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $cashier->id,
        'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);

    return PosOrder::create([
        'store_id' => $store->id,
        'pos_session_id' => $session->id,
        'cashier_id' => $cashier->id,
        'receipt_number' => 'RCP-' . uniqid(),
        'status' => $status,
        'fulfillment_status' => FulfillmentStatus::Completed,
        'subtotal' => $total,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => $total,
        'payment_method' => 'cash',
        'amount_paid' => $total,
        'change_amount' => 0,
        'customer_name' => 'Walk-in',
        'created_at' => $createdAt ?? now(),
    ]);
}

function ownerMetricsOnlineOrder(Store $store, float $total, OrderStatus $status = OrderStatus::Pending, ?\DateTimeInterface $createdAt = null): Order
{
    return Order::factory()->create([
        'store_id' => $store->id,
        'total' => $total,
        'status' => $status,
        'created_at' => $createdAt ?? now(),
    ]);
}

it('includes both POS and online orders in today_sales', function (): void {
    [, $store] = ownerMetricsWorkspace();
    $owner = $store->owner;
    ownerMetricsPosOrder($store, $owner, 100.0);
    ownerMetricsOnlineOrder($store, 300.0);

    $stats = app(OwnerDashboardMetricsService::class)->build($store)['stats'];

    expect($stats['today_sales'])->toBe(400.0)
        ->and($stats['today_pos_sales'])->toBe(100.0)
        ->and($stats['today_online_sales'])->toBe(300.0);
});

it('includes both POS and online orders in today_orders, without double-counting', function (): void {
    [, $store] = ownerMetricsWorkspace();
    $owner = $store->owner;
    ownerMetricsPosOrder($store, $owner, 50.0);
    ownerMetricsPosOrder($store, $owner, 50.0);
    ownerMetricsOnlineOrder($store, 75.0);

    $stats = app(OwnerDashboardMetricsService::class)->build($store)['stats'];

    expect($stats['today_orders'])->toBe(3)
        ->and($stats['today_total_orders'])->toBe(3);
});

it('includes both POS and online orders in month_revenue', function (): void {
    [, $store] = ownerMetricsWorkspace();
    $owner = $store->owner;
    $earlierThisMonth = now()->startOfMonth()->addDays(2);

    ownerMetricsPosOrder($store, $owner, 120.0, 'completed', $earlierThisMonth);
    ownerMetricsOnlineOrder($store, 180.0, OrderStatus::Confirmed, $earlierThisMonth);
    // Also happening today — must be included in the same month total.
    ownerMetricsPosOrder($store, $owner, 10.0);
    ownerMetricsOnlineOrder($store, 20.0);

    $stats = app(OwnerDashboardMetricsService::class)->build($store)['stats'];

    expect($stats['month_revenue'])->toBe(330.0)
        ->and($stats['month_pos_revenue'])->toBe(130.0)
        ->and($stats['month_online_revenue'])->toBe(200.0);
});

it('excludes cancelled online orders and cancelled POS orders from revenue and order counts', function (): void {
    [, $store] = ownerMetricsWorkspace();
    $owner = $store->owner;
    ownerMetricsOnlineOrder($store, 500.0, OrderStatus::Cancelled);
    ownerMetricsPosOrder($store, $owner, 200.0, 'cancelled');
    ownerMetricsOnlineOrder($store, 60.0, OrderStatus::Confirmed);

    $stats = app(OwnerDashboardMetricsService::class)->build($store)['stats'];

    expect($stats['today_sales'])->toBe(60.0)
        ->and($stats['today_orders'])->toBe(1);
});

it('counts a pending_delivery POS order as revenue (sale already happened, only delivery is pending)', function (): void {
    [, $store] = ownerMetricsWorkspace();
    $owner = $store->owner;
    ownerMetricsPosOrder($store, $owner, 90.0, 'pending_delivery');

    $stats = app(OwnerDashboardMetricsService::class)->build($store)['stats'];

    expect($stats['today_sales'])->toBe(90.0)
        ->and($stats['today_orders'])->toBe(1);
});

it('includes both POS and online rows in recent_orders', function (): void {
    [, $store] = ownerMetricsWorkspace();
    $owner = $store->owner;
    ownerMetricsPosOrder($store, $owner, 40.0);
    ownerMetricsOnlineOrder($store, 60.0);

    $result = app(OwnerDashboardMetricsService::class)->build($store);

    $origins = collect($result['recent_orders'])->pluck('origin')->sort()->values()->all();
    expect($origins)->toBe(['online', 'pos']);
});

it('uses the same status set for pending_deliveries count and the pending_bons list', function (): void {
    [, $store] = ownerMetricsWorkspace();

    foreach (['pending', 'preparing', 'ready', 'shipped', 'delivered', 'cancelled'] as $status) {
        BonDeLivraison::create([
            'store_id' => $store->id,
            'bon_number' => 'BON-' . strtoupper($status) . '-' . uniqid(),
            'status' => $status,
            'issued_date' => now()->toDateString(),
            'customer_name' => 'Customer',
            'delivery_address' => 'Somewhere',
        ]);
    }

    $result = app(OwnerDashboardMetricsService::class)->build($store);

    // delivered + cancelled are excluded from both; the other 4 are "pending".
    expect($result['stats']['pending_deliveries'])->toBe(4)
        ->and($result['pending_bons'])->toHaveCount(4)
        ->and(collect($result['pending_bons'])->pluck('status')->sort()->values()->all())
        ->toBe(['pending', 'preparing', 'ready', 'shipped']);
});

it('keeps the low_stock_products output shape unchanged for a never-stocked product', function (): void {
    [, $store] = ownerMetricsWorkspace();
    Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Never Stocked Widget', 'sku' => 'NSW-1',
        'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $result = app(OwnerDashboardMetricsService::class)->build($store);

    expect($result['stats']['total_products'])->toBe(1)
        ->and($result['stats']['low_stock_count'])->toBe(1)
        ->and($result['low_stock_products'])->toHaveCount(1);

    $row = collect($result['low_stock_products'])->first();
    expect(array_keys($row))->toBe(['id', 'name', 'sku', 'stock', 'image_url'])
        ->and($row['sku'])->toBe('NSW-1')
        ->and($row['stock'])->toBe(0);
});

it('does not attach team_activity for a viewer with no team/operations/orders-manage permission', function (): void {
    [, $store] = ownerMetricsWorkspace('Viewer Owner Fallback Store');
    $viewer = ownerMetricsMember($store, 'viewer');

    $this->actingAs($viewer)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'owner')
            ->where('team_activity', null));
});

it('attaches team_activity for the privileged store owner', function (): void {
    [$owner] = ownerMetricsWorkspace('Privileged Owner Store');

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard_kind', 'owner')
            ->has('team_activity.queues'));
});
