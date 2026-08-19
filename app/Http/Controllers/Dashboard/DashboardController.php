<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BonDeLivraison;
use App\Models\Facture;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StoreMember;
use App\Support\OrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const LOW_STOCK_THRESHOLD = 10;

    public function index(Request $request): Response|RedirectResponse
    {
        $user  = $request->user();

        // Defense in depth: the confine_driver middleware already redirects a
        // delivery agent off every manager route, but guard the manager landing
        // page itself so a driver can never render the dashboard even if the
        // middleware chain changes.
        if ($user->isDeliveryOnlyAgent()) {
            return redirect('/dashboard/my-deliveries');
        }

        $store = $user->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Index', [
                'store'              => null,
                'stats'              => $this->emptyStats(),
                'active_session'     => null,
                'recent_orders'      => [],
                'low_stock_products' => [],
                'recent_factures'    => [],
                'pending_bons'       => [],
            ]);
        }

        $today        = today();
        $monthStart   = now()->startOfMonth();

        // Stock aggregation: products have no `stock` column — totals live in stocks table
        $productsWithStock = Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
            ->get(['id', 'name', 'sku', 'featured_image', 'price']);

        $totalProducts = $productsWithStock->count();
        $lowStockBag   = $productsWithStock->filter(
            fn ($p) => (int) ($p->total_stock ?? 0) <= self::LOW_STOCK_THRESHOLD
        );
        $lowStockCount = $lowStockBag->count();

        $stats = [
            'today_sales'        => (float) PosOrder::query()
                                        ->where('store_id', $store->id)
                                        ->whereDate('created_at', $today)
                                        ->sum('total_amount'),
            'today_orders'       => PosOrder::query()
                                        ->where('store_id', $store->id)
                                        ->whereDate('created_at', $today)
                                        ->count(),
            'month_revenue'      => (float) PosOrder::query()
                                        ->where('store_id', $store->id)
                                        ->where('created_at', '>=', $monthStart)
                                        ->sum('total_amount'),
            'total_products'     => $totalProducts,
            'low_stock_count'    => $lowStockCount,
            'pending_deliveries' => BonDeLivraison::query()
                                        ->where('store_id', $store->id)
                                        ->whereIn('status', ['pending', 'preparing', 'ready'])
                                        ->count(),
            'unpaid_invoices'    => (float) Facture::query()
                                        ->where('store_id', $store->id)
                                        ->where('payment_status', 'unpaid')
                                        ->sum('total_amount'),
            'team_count'         => StoreMember::query()
                                        ->where('store_id', $store->id)
                                        ->where('is_active', true)
                                        ->count(),
        ];

        $activeSession = PosSession::query()
            ->where('store_id', $store->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        // Recent orders span both channels — POS sales and online orders — so
        // the dashboard reflects everything selling, not just the till.
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

        $recentOrders = $recentPos->concat($recentOnline)
            ->sortByDesc('created_at')
            ->take(8)
            ->values();

        $lowStockProducts = $lowStockBag
            ->sortBy(fn ($p) => (int) ($p->total_stock ?? 0))
            ->take(5)
            ->map(fn ($p) => [
                'id'        => $p->id,
                'name'      => $p->name,
                'sku'       => $p->sku,
                'stock'     => (int) ($p->total_stock ?? 0),
                'image_url' => $p->featured_image,
            ])
            ->values();

        $recentFactures = Facture::query()
            ->where('store_id', $store->id)
            ->latest('invoice_date')
            ->limit(5)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'payment_status', 'invoice_date']);

        $pendingBons = BonDeLivraison::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['pending', 'preparing', 'shipped'])
            ->latest()
            ->limit(5)
            ->get(['id', 'bon_number', 'customer_name', 'status', 'expected_delivery_date']);

        return Inertia::render('Dashboard/Index', [
            'store' => [
                'id'       => $store->id,
                'name'     => $store->name,
                'currency' => $store->currency ?? 'MAD',
            ],
            'stats'              => $stats,
            'active_session'     => $activeSession === null ? null : [
                'id'              => $activeSession->id,
                'opening_balance' => (float) $activeSession->opening_balance,
                'total_sales'     => (float) $activeSession->total_sales,
                'opened_at'       => $activeSession->opened_at?->diffForHumans(),
            ],
            'recent_orders'      => $recentOrders,
            'low_stock_products' => $lowStockProducts,
            'recent_factures'    => $recentFactures,
            'pending_bons'       => $pendingBons,
        ]);
    }

    private function emptyStats(): array
    {
        return [
            'today_sales'        => 0,
            'today_orders'       => 0,
            'month_revenue'      => 0,
            'total_products'     => 0,
            'low_stock_count'    => 0,
            'pending_deliveries' => 0,
            'unpaid_invoices'    => 0,
            'team_count'         => 0,
        ];
    }
}
