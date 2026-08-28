<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\FulfillmentStatus;
use App\Models\AgentActivityEvent;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\OrderAssignmentService;
use Carbon\CarbonInterface;

/**
 * Operations-control metrics: queue sizes, waiting-stock/delayed-order
 * counts, and a per-agent leaderboard. The leaderboard reuses
 * OrderAssignmentService::workload() verbatim (already the permission- and
 * phase-scoped "who can work this queue, with their current load" query
 * used by the department pages) rather than re-deriving that candidate list.
 */
class SupervisorDashboardMetricsService
{
    private const OPEN_PHASES = ['confirmation', 'fulfillment', 'delivery'];

    public function __construct(
        private readonly OrderAssignmentService $assignments,
    ) {}

    public function build(User $viewer, Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'queues' => $this->queueSizes($store),
            'waiting_stock_count' => $this->countByStatuses($store, [FulfillmentStatus::WaitingForStock]),
            'delayed_orders_count' => $this->delayedOrdersCount($store),
            'team_activity_today' => $this->teamActivitySummary($store, $from, $to),
            'leaderboard' => [
                'confirmation' => $this->assignments->workload($store, 'orders.confirm', $viewer, 'confirmation'),
                'fulfillment' => $this->assignments->workload($store, 'orders.fulfil', $viewer, 'fulfillment'),
                'delivery' => $this->assignments->workload($store, 'orders.dispatch', $viewer, 'delivery'),
            ],
        ];
    }

    /** @return array<string, int> phase => open order count */
    private function queueSizes(Store $store): array
    {
        $sizes = array_fill_keys(self::OPEN_PHASES, 0);

        foreach (FulfillmentStatus::cases() as $status) {
            if (! in_array($status->phase(), self::OPEN_PHASES, true)) {
                continue;
            }

            $sizes[$status->phase()] += $this->countByStatuses($store, [$status]);
        }

        return $sizes;
    }

    /** @param array<int, FulfillmentStatus> $statuses */
    private function countByStatuses(Store $store, array $statuses): int
    {
        $values = array_map(fn (FulfillmentStatus $s) => $s->value, $statuses);

        return Order::query()->where('store_id', $store->id)->whereIn('fulfillment_status', $values)->count()
            + PosOrder::query()->where('store_id', $store->id)->whereIn('fulfillment_status', $values)->count();
    }

    /** Orders still open (not delivered/completed/cancelled/in the returns flow) past a simple age threshold. */
    private function delayedOrdersCount(Store $store, int $hoursThreshold = 24): int
    {
        $cutoff = now()->subHours($hoursThreshold);

        $openStatuses = array_map(
            fn (FulfillmentStatus $s) => $s->value,
            array_filter(FulfillmentStatus::cases(), fn (FulfillmentStatus $s) => in_array($s->phase(), self::OPEN_PHASES, true)),
        );

        return Order::query()->where('store_id', $store->id)->whereIn('fulfillment_status', $openStatuses)->where('created_at', '<', $cutoff)->count()
            + PosOrder::query()->where('store_id', $store->id)->whereIn('fulfillment_status', $openStatuses)->where('created_at', '<', $cutoff)->count();
    }

    /** @return array<string, int> event_type => count, across the whole team */
    private function teamActivitySummary(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        return AgentActivityEvent::query()
            ->forStore($store->id)
            ->between($from, $to)
            ->get(['event_type'])
            ->countBy('event_type')
            ->all();
    }
}
