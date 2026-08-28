<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\OrderStatus;
use App\Models\BonDeLivraison;
use App\Models\Facture;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\OrderPresenter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The store owner/admin business-overview dashboard. `today_sales`,
 * `today_orders` and `month_revenue` cover the WHOLE business — POS sales
 * AND online orders (Shopify/WooCommerce/YouCan/manual) combined — because an
 * owner asking "how much did we sell today" means every channel, not just
 * the till. `teamActivitySummary()` is the only block that isn't pure
 * business-overview data, and it is only ever attached by the controller
 * when the viewer holds team.manage/operations.supervise/orders.manage —
 * never computed, let alone shown, for an unauthorized viewer.
 */
class OwnerDashboardMetricsService
{
    private const LOW_STOCK_THRESHOLD = 10;

    /**
     * Delivery-note (BonDeLivraison) statuses that count as "still pending" —
     * i.e. the customer hasn't received the goods yet and the note hasn't
     * been cancelled. `shipped` is included: the parcel is out but not yet
     * in the customer's hands, so it's still open work from the business's
     * point of view. Only `delivered` (done) and `cancelled` (closed, never
     * happened) are excluded. Shared by the pending_deliveries COUNT and the
     * pendingBons LIST so the two can never drift out of sync with each other.
     */
    private const PENDING_DELIVERY_STATUSES = ['pending', 'preparing', 'ready', 'shipped'];

    /**
     * OrderStatus values that do NOT represent real revenue for an online
     * order. Cancelled is the one status this codebase's connectors/workflow
     * actually produce for "this never became a sale" (see
     * Order::scopeCancelled()/isCancelled()); Failed is kept here
     * defensively even though nothing currently sets it.
     */
    private const ONLINE_REVENUE_EXCLUDED_STATUSES = [OrderStatus::Cancelled, OrderStatus::Failed];

    /**
     * PosOrder.status values that do NOT represent real revenue. A cancelled
     * POS sale never happened commercially. `pending_delivery` is NOT
     * excluded — it's a fulfillment concern (goods already sold, delivery
     * still pending), not a "did the sale happen" concern; see the
     * source-column comment in OrderPresenter::pos().
     */
    private const POS_REVENUE_EXCLUDED_STATUSES = ['cancelled'];

    public function __construct(
        private readonly SupervisorDashboardMetricsService $supervisorMetrics,
    ) {}

    public function build(Store $store): array
    {
        $today = AgentDashboardMetricsService::rangeFor('today');
        $month = AgentDashboardMetricsService::rangeFor('month');

        $todayPosSales = $this->posSalesTotal($store, $today['from'], $today['to']);
        $todayOnlineSales = $this->onlineSalesTotal($store, $today['from'], $today['to']);
        $monthPosRevenue = $this->posSalesTotal($store, $month['from'], $month['to']);
        $monthOnlineRevenue = $this->onlineSalesTotal($store, $month['from'], $month['to']);

        $todayPosOrders = $this->posOrdersCount($store, $today['from'], $today['to']);
        $todayOnlineOrders = $this->onlineOrdersCount($store, $today['from'], $today['to']);

        $totalProducts = $this->totalProductsCount($store);
        $lowStockCount = $this->lowStockCount($store);
        $lowStockProducts = $this->lowStockProductsPreview($store);

        $stats = [
            // today_sales / month_revenue: POS + online combined, for orders
            // whose commercial status represents a real sale (see the
            // *_REVENUE_EXCLUDED_STATUSES constants above). This is the
            // whole-business figure an owner expects — not POS-only.
            'today_sales' => round($todayPosSales + $todayOnlineSales, 2),
            'month_revenue' => round($monthPosRevenue + $monthOnlineRevenue, 2),
            'today_orders' => $todayPosOrders + $todayOnlineOrders,

            // Additive, backward-compatible breakdown. The current frontend
            // does not read these — kept for anything that wants the
            // per-channel split later without another migration of callers.
            'today_pos_sales' => round($todayPosSales, 2),
            'today_online_sales' => round($todayOnlineSales, 2),
            'month_pos_revenue' => round($monthPosRevenue, 2),
            'month_online_revenue' => round($monthOnlineRevenue, 2),
            'today_total_orders' => $todayPosOrders + $todayOnlineOrders,

            'total_products' => $totalProducts,
            'low_stock_count' => $lowStockCount,
            'pending_deliveries' => BonDeLivraison::query()
                ->where('store_id', $store->id)
                ->whereIn('status', self::PENDING_DELIVERY_STATUSES)
                ->count(),
            'unpaid_invoices' => (float) Facture::query()
                ->where('store_id', $store->id)
                ->where('payment_status', 'unpaid')
                ->sum('total_amount'),
            'team_count' => StoreMember::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->count(),
        ];

        $activeSession = PosSession::query()
            ->where('store_id', $store->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        $recentPos = PosOrder::query()
            ->where('store_id', $store->id)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (PosOrder $o) => OrderPresenter::posRow($o));

        $recentOnline = Order::query()
            ->where('store_id', $store->id)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Order $o) => OrderPresenter::onlineRow($o));

        // POS and online orders live in two entirely separate tables (a POS
        // sale is never synced into `orders`), so concatenating them can
        // never duplicate a single sale — no dedup step is needed here.
        $recentOrders = $recentPos->concat($recentOnline)
            ->sortByDesc('created_at')
            ->take(8)
            ->values();

        $recentFactures = Facture::query()
            ->where('store_id', $store->id)
            ->latest('invoice_date')
            ->limit(5)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'payment_status', 'invoice_date']);

        $pendingBons = BonDeLivraison::query()
            ->where('store_id', $store->id)
            ->whereIn('status', self::PENDING_DELIVERY_STATUSES)
            ->latest()
            ->limit(5)
            ->get(['id', 'bon_number', 'customer_name', 'status', 'expected_delivery_date']);

        return [
            'stats' => $stats,
            'active_session' => $activeSession === null ? null : [
                'id' => $activeSession->id,
                'opening_balance' => (float) $activeSession->opening_balance,
                'total_sales' => (float) $activeSession->total_sales,
                'opened_at' => $activeSession->opened_at?->diffForHumans(),
            ],
            'recent_orders' => $recentOrders,
            'low_stock_products' => $lowStockProducts,
            'recent_factures' => $recentFactures,
            'pending_bons' => $pendingBons,
        ];
    }

    public static function emptyStats(): array
    {
        return [
            'today_sales' => 0,
            'today_orders' => 0,
            'month_revenue' => 0,
            'today_pos_sales' => 0,
            'today_online_sales' => 0,
            'month_pos_revenue' => 0,
            'month_online_revenue' => 0,
            'today_total_orders' => 0,
            'total_products' => 0,
            'low_stock_count' => 0,
            'pending_deliveries' => 0,
            'unpaid_invoices' => 0,
            'team_count' => 0,
        ];
    }

    /**
     * Permission-gated team/operations summary — the caller (DashboardController)
     * only attaches this to the response when the viewer holds team.manage,
     * operations.supervise, or orders.manage. Never computed otherwise.
     */
    public function teamActivitySummary(User $viewer, Store $store): array
    {
        $range = AgentDashboardMetricsService::rangeFor('today');

        return $this->supervisorMetrics->build($viewer, $store, $range['from'], $range['to']);
    }

    // --- Revenue helpers (POS + online, kept separate so neither channel's
    // query logic is duplicated inline in build()) ------------------------

    private function posSalesTotal(Store $store, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) $this->posOrdersQuery($store, $from, $to)->sum('total_amount');
    }

    private function onlineSalesTotal(Store $store, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) $this->onlineOrdersQuery($store, $from, $to)->sum('total');
    }

    private function posOrdersCount(Store $store, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->posOrdersQuery($store, $from, $to)->count();
    }

    private function onlineOrdersCount(Store $store, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->onlineOrdersQuery($store, $from, $to)->count();
    }

    private function posOrdersQuery(Store $store, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return PosOrder::query()
            ->where('store_id', $store->id)
            ->whereNotIn('status', self::POS_REVENUE_EXCLUDED_STATUSES)
            ->whereBetween('created_at', [$from, $to]);
    }

    private function onlineOrdersQuery(Store $store, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->whereNotIn('status', self::ONLINE_REVENUE_EXCLUDED_STATUSES)
            ->whereBetween('created_at', [$from, $to]);
    }

    // --- Catalog helpers ---------------------------------------------------

    private function totalProductsCount(Store $store): int
    {
        return Product::query()->where('store_id', $store->id)->count();
    }

    /**
     * Product::scopeWithSellableStock() computes `total_stock` as a
     * correlated SUM subquery (a SELECT-list expression, not a JOIN+GROUP
     * BY), so it can be referenced directly in HAVING without ever pulling
     * product rows into memory just to filter/count them — no N+1, no
     * full-catalog load. COALESCE(...,0) mirrors the old PHP-side `?? 0`:
     * a product with zero stock rows (SUM returns NULL) still counts as
     * "0 in stock", i.e. low stock.
     */
    private function lowStockCount(Store $store): int
    {
        // groupBy('products.id') is required for the havingRaw() below to be
        // valid on SQLite (used in tests) — MySQL alone would accept a HAVING
        // referencing a SELECT alias with no GROUP BY, SQLite does not. Every
        // product id is already unique, so grouping by it changes nothing
        // about which rows are counted.
        return Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
            ->groupBy('products.id')
            ->havingRaw('COALESCE(total_stock, 0) <= ?', [self::LOW_STOCK_THRESHOLD])
            ->count();
    }

    /**
     * Only the lowest-`$limit` low-stock products are ever fetched — the
     * DB does the filtering and ordering, not PHP. Output shape is
     * unchanged: id/name/sku/stock/image_url.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function lowStockProductsPreview(Store $store, int $limit = 5): Collection
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
            ->groupBy('products.id')
            ->havingRaw('COALESCE(total_stock, 0) <= ?', [self::LOW_STOCK_THRESHOLD])
            ->orderByRaw('COALESCE(total_stock, 0) ASC')
            ->limit($limit)
            ->get(['products.id', 'products.name', 'products.sku', 'products.featured_image', 'products.price'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => (int) ($p->total_stock ?? 0),
                'image_url' => $p->featured_image,
            ])
            ->values();
    }
}
