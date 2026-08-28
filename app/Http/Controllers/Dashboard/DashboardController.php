<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AgentActivityEvent;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Metrics\AgentDashboardMetricsService;
use App\Services\Metrics\AgentScorePreviewService;
use App\Services\Metrics\OwnerDashboardMetricsService;
use App\Services\Metrics\SupervisorDashboardMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard renders a DIFFERENT dashboard depending on the viewer's role —
 * see resolveDashboardKind(). Same route, same Inertia page component
 * ('Dashboard/Index'); the page itself is a thin router keyed on the
 * `dashboard_kind` prop (see resources/js/Pages/Dashboard/Index.jsx).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly OwnerDashboardMetricsService $ownerMetrics,
        private readonly SupervisorDashboardMetricsService $supervisorMetrics,
        private readonly AgentDashboardMetricsService $agentMetrics,
        private readonly AgentScorePreviewService $pointsPreview,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Defense in depth: the confine_driver middleware already redirects a
        // delivery agent off every manager route, but guard the manager landing
        // page itself so a driver can never render the dashboard even if the
        // middleware chain changes. Unchanged from before this phase.
        if ($user->isDeliveryOnlyAgent()) {
            return redirect('/dashboard/my-deliveries');
        }

        $store = $user->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'owner',
                'store' => null,
                'stats' => OwnerDashboardMetricsService::emptyStats(),
                'active_session' => null,
                'recent_orders' => [],
                'low_stock_products' => [],
                'recent_factures' => [],
                'pending_bons' => [],
            ]);
        }

        $kind = $this->resolveDashboardKind($user, $store);
        $storeProp = ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'];

        $today = AgentDashboardMetricsService::rangeFor('today');
        $week = AgentDashboardMetricsService::rangeFor('week');
        $month = AgentDashboardMetricsService::rangeFor('month');

        return match ($kind) {
            'confirmation' => Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'confirmation',
                'store' => $storeProp,
                ...$this->confirmationProps($user, $store, $today, $week, $month),
            ]),
            'fulfillment' => Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'fulfillment',
                'store' => $storeProp,
                ...$this->fulfillmentProps($user, $store, $today, $week),
            ]),
            'delivery' => Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'delivery',
                'store' => $storeProp,
                ...$this->deliveryProps($user, $store, $today, $week),
            ]),
            'inventory' => Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'inventory',
                'store' => $storeProp,
                ...$this->inventoryProps($store, $today),
            ]),
            'supervisor' => Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'supervisor',
                'store' => $storeProp,
                'operations' => $this->supervisorMetrics->build($user, $store, $today['from'], $today['to']),
            ]),
            default => Inertia::render('Dashboard/Index', [
                'dashboard_kind' => 'owner',
                'store' => $storeProp,
                ...$this->ownerMetrics->build($store),
                // Never computed, let alone shown, unless the viewer holds one
                // of these — an owner-tier user always does, but a custom
                // "owner fallback" role might not.
                'team_activity' => $this->canSeeTeamActivity($user, $store)
                    ? $this->ownerMetrics->teamActivitySummary($user, $store)
                    : null,
            ]),
        };
    }

    /**
     * First match wins. Falls back to 'owner' for anyone who doesn't match a
     * specific operational role (owner-tier, viewer, custom roles) — the
     * existing business-overview dashboard, unchanged.
     */
    private function resolveDashboardKind(User $user, Store $store): string
    {
        $roleSlug = $user->accessProfileForStore($store)['roleSlug'] ?? null;

        $ownerTier = ['organization-owner', 'organization-admin', 'store-owner', 'administrator', 'agency-operator'];

        return match (true) {
            in_array($roleSlug, $ownerTier, true) => 'owner',
            $roleSlug === 'supervisor' || $user->hasStorePermission($store, 'operations.supervise') => 'supervisor',
            $roleSlug === 'confirmation-agent' => 'confirmation',
            $roleSlug === 'warehouse' => 'fulfillment',
            $roleSlug === 'dispatcher' => 'delivery',
            // Closest fit: an inspector's work is stock-disposition-driven.
            // Documented judgement call — see the plan's known limitations.
            $roleSlug === 'inspector' => 'inventory',
            default => 'owner',
        };
    }

    private function canSeeTeamActivity(User $user, Store $store): bool
    {
        return $user->hasStorePermission($store, 'team.manage')
            || $user->hasStorePermission($store, 'operations.supervise')
            || $user->hasStorePermission($store, 'orders.manage');
    }

    /** @return array<string, mixed> */
    private function confirmationProps(User $user, Store $store, array $today, array $week, array $month): array
    {
        $pendingStatus = FulfillmentStatus::Pending->value;

        return [
            'waiting_count' => Order::where('store_id', $store->id)->where('fulfillment_status', $pendingStatus)->whereNull('assigned_to')->count()
                + PosOrder::where('store_id', $store->id)->where('fulfillment_status', $pendingStatus)->whereNull('assigned_to')->count(),
            'claimed_by_me_count' => Order::where('store_id', $store->id)->where('fulfillment_status', $pendingStatus)->where('assigned_to', $user->id)->count()
                + PosOrder::where('store_id', $store->id)->where('fulfillment_status', $pendingStatus)->where('assigned_to', $user->id)->count(),
            'today' => $this->agentMetrics->confirmationMetrics($user, $store, $today['from'], $today['to']),
            'week' => $this->agentMetrics->confirmationMetrics($user, $store, $week['from'], $week['to']),
            'month' => $this->agentMetrics->confirmationMetrics($user, $store, $month['from'], $month['to']),
            'points_preview' => $this->pointsPreview->previewFor($user, $store, $today['from'], $today['to']),
        ];
    }

    /** @return array<string, mixed> */
    private function fulfillmentProps(User $user, Store $store, array $today, array $week): array
    {
        $fulfillmentStatuses = array_map(
            fn (FulfillmentStatus $s) => $s->value,
            array_filter(FulfillmentStatus::cases(), fn (FulfillmentStatus $s) => $s->phase() === 'fulfillment'),
        );

        return [
            'assigned_to_me_count' => Order::where('store_id', $store->id)->whereIn('fulfillment_status', $fulfillmentStatuses)->where('assigned_to', $user->id)->count()
                + PosOrder::where('store_id', $store->id)->whereIn('fulfillment_status', $fulfillmentStatuses)->where('assigned_to', $user->id)->count(),
            'waiting_stock_count' => Order::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::WaitingForStock->value)->count()
                + PosOrder::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::WaitingForStock->value)->count(),
            'ready_for_dispatch_count' => Order::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::ReadyForDelivery->value)->count()
                + PosOrder::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::ReadyForDelivery->value)->count(),
            'today' => $this->agentMetrics->fulfillmentMetrics($user, $store, $today['from'], $today['to']),
            'week' => $this->agentMetrics->fulfillmentMetrics($user, $store, $week['from'], $week['to']),
            'points_preview' => $this->pointsPreview->previewFor($user, $store, $today['from'], $today['to']),
        ];
    }

    /** @return array<string, mixed> */
    private function deliveryProps(User $user, Store $store, array $today, array $week): array
    {
        return [
            'returns_to_inspect_count' => Order::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::Returned->value)->count()
                + PosOrder::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::Returned->value)->count(),
            'today' => $this->agentMetrics->deliveryMetrics($user, $store, $today['from'], $today['to']),
            'week' => $this->agentMetrics->deliveryMetrics($user, $store, $week['from'], $week['to']),
            'points_preview' => $this->pointsPreview->previewFor($user, $store, $today['from'], $today['to']),
        ];
    }

    /** @return array<string, mixed> */
    private function inventoryProps(Store $store, array $today): array
    {
        $lowStockCount = Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
            ->get(['id'])
            ->filter(fn ($p) => (int) ($p->total_stock ?? 0) <= 10)
            ->count();

        return [
            'low_stock_count' => $lowStockCount,
            'waiting_stock_count' => Order::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::WaitingForStock->value)->count()
                + PosOrder::where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::WaitingForStock->value)->count(),
            'pending_transfers_count' => InventoryTransfer::query()
                ->whereHas('destinationWarehouse', fn ($q) => $q->whereHas('stores', fn ($q2) => $q2->where('stores.id', $store->id)))
                ->where('status', InventoryTransfer::IN_TRANSIT)
                ->count(),
            'adjustments_today' => AgentActivityEvent::query()
                ->forStore($store->id)
                ->ofType(AgentActivityEvent::INVENTORY_ADJUSTED)
                ->between($today['from'], $today['to'])
                ->count(),
            'transfers_received_today' => AgentActivityEvent::query()
                ->forStore($store->id)
                ->ofType(AgentActivityEvent::STOCK_TRANSFER_RECEIVED)
                ->between($today['from'], $today['to'])
                ->count(),
        ];
    }
}
