<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\AgentActivityEvent;
use App\Models\AgentScoreRule;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * "Performance points preview" — POINTS FOUNDATION ONLY, per the brief. Reads
 * real agent_activity_events for the period and sums them against the
 * configurable agent_score_rules (seeded defaults; no admin UI to edit them
 * yet). Computed live, never persisted — there is no agent_score_events
 * table, and nothing here is ever read by payroll/invoicing (no such system
 * exists in this codebase to wire into).
 */
class AgentScorePreviewService
{
    /**
     * @return array{total_points: int, breakdown: array<int, array{event_type: string, label: string, count: int, points: int}>}
     */
    public function previewFor(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $rates = AgentScoreRule::effectiveRatesFor($store->organization_id);
        $labels = AgentScoreRule::query()->active()->pluck('label', 'event_type');

        $counts = AgentActivityEvent::query()
            ->forUser($user->id)
            ->forStore($store->id)
            ->between($from, $to)
            ->get(['event_type'])
            ->countBy('event_type');

        $breakdown = [];
        $total = 0;

        foreach ($counts as $eventType => $count) {
            $rate = $rates[$eventType] ?? 0;

            // No configured rule (or explicitly neutral) — skip the row
            // rather than showing noisy zero-point lines.
            if ($rate === 0) {
                continue;
            }

            $points = $rate * $count;
            $total += $points;

            $breakdown[] = [
                'event_type' => $eventType,
                'label' => $labels[$eventType] ?? $eventType,
                'count' => $count,
                'points' => $points,
            ];
        }

        return ['total_points' => $total, 'breakdown' => $breakdown];
    }
}
