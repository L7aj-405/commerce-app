<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum FinancePayoutFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    /** Real providers that pay every ~24h — one period per calendar day. See FinanceCodPayoutPeriodService. */
    case Daily = 'daily';
    /** Real providers that pay per delivery / same-day, with no waiting period at all — also doubles as the practical "test the whole settlement flow without waiting a week" setting. */
    case Instant = 'instant';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Biweekly => 'Every 2 weeks',
            self::Monthly => 'Monthly',
            self::Daily => 'Daily / 24h',
            self::Instant => 'Instant / Same day',
        };
    }

    /** Advance a period-start date to the next period's start for this frequency. */
    public function advance(CarbonImmutable $periodStart): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $periodStart->addWeek(),
            self::Biweekly => $periodStart->addWeeks(2),
            self::Monthly => $periodStart->addMonthNoOverflow(),
            // One calendar day, for both — see FinanceCodPayoutPeriodService::
            // resolvePeriodBounds(), which computes these two directly from
            // referenceDate instead of walking an anchor forward.
            self::Daily, self::Instant => $periodStart->addDay(),
        };
    }

    /**
     * Whether a period for this frequency should skip the normal
     * "accumulating until the period closes" wait and go straight to
     * ready_to_verify the moment it has any delivered order at all — see
     * FinanceCodPayoutPeriodService::summarize(). Instant always does;
     * Daily only does when there's no extra payout delay configured on top
     * (payout_delay_days = 0) — a daily provider WITH a delay still waits
     * for that delay, exactly like weekly/biweekly/monthly do.
     */
    public function skipsAccumulating(int $payoutDelayDays): bool
    {
        return match ($this) {
            self::Instant => true,
            self::Daily => $payoutDelayDays === 0,
            default => false,
        };
    }
}
