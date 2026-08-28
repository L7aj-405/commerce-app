<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\AgentActivityEvent;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only metrics for a single agent's own confirmation/fulfillment/
 * delivery dashboard — always scoped to exactly one user + one store. Every
 * number comes from the agent_activity_events ledger (see
 * App\Services\Activity\AgentActivityRecorder); nothing here is fabricated,
 * and a metric with no supporting events degrades to 0/null rather than
 * guessing.
 */
class AgentDashboardMetricsService
{
    /** @return array{from: CarbonInterface, to: CarbonInterface} */
    public static function rangeFor(string $period): array
    {
        $now = now();

        return match ($period) {
            'week' => ['from' => $now->clone()->startOfWeek(), 'to' => $now->clone()->endOfDay()],
            'month' => ['from' => $now->clone()->startOfMonth(), 'to' => $now->clone()->endOfDay()],
            default => ['from' => $now->clone()->startOfDay(), 'to' => $now->clone()->endOfDay()],
        };
    }

    public function confirmationMetrics(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = $this->eventsFor($user, $store, $from, $to, [
            AgentActivityEvent::CONFIRMATION_CLAIMED,
            AgentActivityEvent::CONFIRMATION_CONFIRMED,
            AgentActivityEvent::CONFIRMATION_CANCELLED,
            AgentActivityEvent::CONFIRMATION_UNREACHABLE,
        ]);

        $confirmed = $events->where('event_type', AgentActivityEvent::CONFIRMATION_CONFIRMED)->count();
        $cancelled = $events->where('event_type', AgentActivityEvent::CONFIRMATION_CANCELLED)->count();

        return [
            'claimed_count' => $events->where('event_type', AgentActivityEvent::CONFIRMATION_CLAIMED)->count(),
            'confirmed_count' => $confirmed,
            'cancelled_count' => $cancelled,
            'unreachable_count' => $events->where('event_type', AgentActivityEvent::CONFIRMATION_UNREACHABLE)->count(),
            'confirmation_rate' => self::rate($confirmed, $confirmed + $cancelled),
            'average_confirmation_time_seconds' => $this->averageSecondsBetween(
                $events, AgentActivityEvent::CONFIRMATION_CLAIMED, AgentActivityEvent::CONFIRMATION_CONFIRMED,
            ),
        ];
    }

    public function fulfillmentMetrics(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = $this->eventsFor($user, $store, $from, $to, [
            AgentActivityEvent::FULFILLMENT_PICKED,
            AgentActivityEvent::FULFILLMENT_PACKED,
            AgentActivityEvent::FULFILLMENT_ERROR_REPORTED,
        ]);

        $picked = $events->where('event_type', AgentActivityEvent::FULFILLMENT_PICKED);
        $packed = $events->where('event_type', AgentActivityEvent::FULFILLMENT_PACKED);

        return [
            'picked_orders_count' => $picked->count(),
            'picked_units_count' => (int) $picked->sum(fn ($e) => (int) ($e->metadata['units'] ?? 0)),
            'packed_orders_count' => $packed->count(),
            'packed_units_count' => (int) $packed->sum(fn ($e) => (int) ($e->metadata['units'] ?? 0)),
            // No error-report action exists in Picking/Packing today — always 0
            // until that trigger exists (see plan's known limitations).
            'error_count' => $events->where('event_type', AgentActivityEvent::FULFILLMENT_ERROR_REPORTED)->count(),
            'average_pack_time_seconds' => $this->averageSecondsBetween(
                $events, AgentActivityEvent::FULFILLMENT_PICKED, AgentActivityEvent::FULFILLMENT_PACKED,
            ),
        ];
    }

    public function deliveryMetrics(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = $this->eventsFor($user, $store, $from, $to, [
            AgentActivityEvent::DELIVERY_ASSIGNED,
            AgentActivityEvent::DELIVERY_DELIVERED,
            AgentActivityEvent::DELIVERY_FAILED,
            AgentActivityEvent::DELIVERY_UNREACHABLE,
        ]);

        $delivered = $events->where('event_type', AgentActivityEvent::DELIVERY_DELIVERED)->count();
        $failed = $events->where('event_type', AgentActivityEvent::DELIVERY_FAILED)->count();
        $unreachable = $events->where('event_type', AgentActivityEvent::DELIVERY_UNREACHABLE)->count();

        $codCollected = (float) $events->where('event_type', AgentActivityEvent::DELIVERY_DELIVERED)
            ->sum(fn ($e) => (float) ($e->metadata['cod_collected'] ?? 0));

        return [
            'assigned_count' => $events->where('event_type', AgentActivityEvent::DELIVERY_ASSIGNED)->count(),
            'delivered_count' => $delivered,
            'failed_count' => $failed,
            'unreachable_count' => $unreachable,
            'cod_collected' => round($codCollected, 2),
            'delivery_success_rate' => self::rate($delivered, $delivered + $failed + $unreachable),
            'average_delivery_time_seconds' => $this->averageSecondsBetween(
                $events, AgentActivityEvent::DELIVERY_ASSIGNED, AgentActivityEvent::DELIVERY_DELIVERED,
            ),
        ];
    }

    /** @param array<int, string> $types */
    private function eventsFor(User $user, Store $store, CarbonInterface $from, CarbonInterface $to, array $types): Collection
    {
        return AgentActivityEvent::query()
            ->forUser($user->id)
            ->forStore($store->id)
            ->ofType($types)
            ->between($from, $to)
            ->get(['event_type', 'order_id', 'occurred_at', 'metadata']);
    }

    /** Average seconds between the same order's $fromType event and its later $toType event. */
    private function averageSecondsBetween(Collection $events, string $fromType, string $toType): ?float
    {
        $starts = $events->where('event_type', $fromType)
            ->filter(fn ($e) => $e->order_id !== null)
            ->keyBy('order_id');

        $durations = $events->where('event_type', $toType)
            ->filter(fn ($e) => $e->order_id !== null)
            ->map(function ($end) use ($starts) {
                $start = $starts->get($end->order_id);

                // Carbon 3's diffInSeconds() is signed by default (Carbon 2 was
                // always absolute) — abs() keeps "average handling time"
                // positive regardless of which Carbon major version runs.
                return $start !== null ? abs($end->occurred_at->diffInSeconds($start->occurred_at)) : null;
            })
            ->filter(fn ($seconds) => $seconds !== null);

        return $durations->isEmpty() ? null : round((float) $durations->avg(), 1);
    }

    private static function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : null;
    }
}
