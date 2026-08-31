<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancePayoutFrequency;
use App\Models\DeliveryProviderFinanceSetting;
use App\Models\FinanceCodSettlement;
use App\Models\Order;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Groups delivered, external-carrier COD orders into payout periods per the
 * task spec — a period is DERIVED live from a provider's finance settings
 * (frequency/anchor/delay) and never persisted on its own; a real
 * FinanceCodSettlement row only appears once an accountant actually verifies
 * a bank transfer for that period (see
 * FinanceCodSettlementService::verifyProviderPeriod()). Browsing/refreshing
 * the External settlements tab never writes anything.
 */
class FinanceCodPayoutPeriodService
{
    /** Safety cap for walking forward from a (possibly very old) anchor date to find the period containing a reference date — mirrors FinanceRecurringExpenseService::MAX_CATCH_UP_PERIODS. */
    private const MAX_PERIOD_LOOKUPS = 1000;

    public function __construct(
        private readonly FinanceOrderTransactionService $orderTransactions,
        private readonly FinanceCodCollectabilityService $collectability,
    ) {}

    /**
     * @return array{period_start: CarbonImmutable, period_end: CarbonImmutable, payout_date: CarbonImmutable}
     */
    public function resolvePeriodBounds(DeliveryProviderFinanceSetting $settings, CarbonImmutable $referenceDate): array
    {
        $frequency = $settings->payout_frequency;

        // Daily/instant periods are always exactly ONE calendar day and
        // never need anchor alignment — every day boundary is already a
        // valid period boundary on its own, regardless of where the anchor
        // sits. Computed directly rather than through the anchor-walk loop
        // below: that loop steps one period at a time, so a Daily/Instant
        // provider whose anchor was set months or years ago would need
        // thousands of 1-day steps to catch up — this skips straight there.
        if (in_array($frequency, [FinancePayoutFrequency::Daily, FinancePayoutFrequency::Instant], true)) {
            $periodStart = $referenceDate->startOfDay();
            $periodEnd = $periodStart;
            $payoutDate = $periodEnd->addDays($settings->payout_delay_days);

            return ['period_start' => $periodStart, 'period_end' => $periodEnd, 'payout_date' => $payoutDate];
        }

        $anchor = $settings->period_anchor_date !== null
            ? CarbonImmutable::parse($settings->period_anchor_date)->startOfDay()
            : $referenceDate->startOfWeek(CarbonImmutable::MONDAY);

        $periodStart = $anchor;
        $iterations = 0;

        // Walk forward from the anchor to the period that CONTAINS
        // referenceDate. Safe for an anchor arbitrarily far in the past —
        // capped so a misconfigured anchor can never loop forever. If the
        // anchor is AFTER referenceDate (e.g. a brand-new provider whose
        // anchor is today), the loop simply never runs and periodStart
        // stays at the anchor itself.
        while ($iterations < self::MAX_PERIOD_LOOKUPS) {
            $nextStart = $frequency->advance($periodStart);

            if ($nextStart->gt($referenceDate)) {
                break;
            }

            $periodStart = $nextStart;
            $iterations++;
        }

        $periodEnd = $frequency->advance($periodStart)->subDay();
        $payoutDate = $periodEnd->addDay()->addDays($settings->payout_delay_days);

        return ['period_start' => $periodStart, 'period_end' => $periodEnd, 'payout_date' => $payoutDate];
    }

    /**
     * Live period summaries for every active, COD-enabled provider with
     * finance settings in this organization.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingPeriods(Organization $organization): Collection
    {
        $settingsList = DeliveryProviderFinanceSetting::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->where('is_cod_enabled', true)
            ->with('provider')
            ->get()
            ->filter(fn (DeliveryProviderFinanceSetting $s) => $s->provider !== null);

        if ($settingsList->isEmpty()) {
            return collect();
        }

        $now = CarbonImmutable::now();

        // An order already attached to a non-cancelled settlement (draft or
        // final) must never be grouped into a NEW virtual period — "one
        // order cannot belong to two active settlement periods".
        $alreadyAssignedOrderIds = FinanceCodSettlement::query()
            ->where('organization_id', $organization->id)
            ->where('status', '!=', 'cancelled')
            ->with('items:id,finance_cod_settlement_id,order_id')
            ->get()
            ->flatMap(fn (FinanceCodSettlement $s) => $s->items->pluck('order_id'));

        $pendingIds = $this->orderTransactions->pendingCodOrderIds($organization->id)->diff($alreadyAssignedOrderIds);

        if ($pendingIds->isEmpty()) {
            return collect();
        }

        $orders = Order::withoutTenancy(fn () => Order::query()
            ->whereIn('id', $pendingIds->all())
            ->with(['shipment.provider', 'store:id,name'])
            ->get())
            ->filter(fn (Order $order) => $order->shipment?->delivered_at !== null && $this->collectability->isCollectable($order));

        return $settingsList
            ->map(function (DeliveryProviderFinanceSetting $settings) use ($orders, $now) {
                $providerOrders = $orders->filter(fn (Order $order) => $order->shipment->provider_code === $settings->provider->code);

                if ($providerOrders->isEmpty()) {
                    return collect();
                }

                $byPeriod = $providerOrders->groupBy(function (Order $order) use ($settings) {
                    $bounds = $this->resolvePeriodBounds($settings, CarbonImmutable::parse($order->shipment->delivered_at));

                    return $bounds['period_start']->toDateString();
                });

                return $byPeriod->map(fn (Collection $group, string $periodStartKey) => $this->summarize($settings, $group, CarbonImmutable::parse($periodStartKey), $now));
            })
            ->flatten(1)
            ->sortBy('period_start')
            ->values();
    }

    /** @return array<string, mixed> */
    private function summarize(DeliveryProviderFinanceSetting $settings, Collection $orders, CarbonImmutable $periodStartRef, CarbonImmutable $now): array
    {
        $bounds = $this->resolvePeriodBounds($settings, $periodStartRef);

        $gross = (float) $orders->sum(fn (Order $o) => (float) $o->total);
        $expectedFees = (float) $orders->sum(fn (Order $o) => $o->shipment->effectiveCarrierFee() ?? 0.0);
        $hasManualRequired = $orders->contains(fn (Order $o) => $o->shipment->fee_source === 'manual_required' || $o->shipment->fee_calculated_at === null);

        // Instant always skips straight to ready_to_verify; Daily only does
        // when there's no extra payout delay on top of the day itself — see
        // FinancePayoutFrequency::skipsAccumulating(). Weekly/biweekly/
        // monthly (and a delayed Daily/Instant) keep the normal
        // accumulating -> ready_to_verify -> overdue progression, unchanged.
        $status = match (true) {
            $settings->payout_frequency->skipsAccumulating($settings->payout_delay_days)
                => $now->startOfDay()->lte($bounds['payout_date']) ? 'ready_to_verify' : 'overdue',
            $now->startOfDay()->lte($bounds['period_end']) => 'accumulating',
            $now->startOfDay()->lte($bounds['payout_date']) => 'ready_to_verify',
            default => 'overdue',
        };

        $daysUntilPayout = (int) floor(($bounds['payout_date']->startOfDay()->timestamp - $now->startOfDay()->timestamp) / 86400);

        return [
            'delivery_provider_id' => $settings->delivery_provider_id,
            'provider_name' => $settings->provider->name,
            'provider_code' => $settings->provider->code,
            'payout_frequency' => $settings->payout_frequency->value,
            'period_start' => $bounds['period_start']->toDateString(),
            'period_end' => $bounds['period_end']->toDateString(),
            'payout_date' => $bounds['payout_date']->toDateString(),
            'delivered_orders_count' => $orders->count(),
            'gross_cod' => round($gross, 2),
            'expected_fees' => round($expectedFees, 2),
            'expected_net' => round($gross - $expectedFees, 2),
            'has_manual_required_fees' => $hasManualRequired,
            'status' => $status,
            'days_until_payout' => $daysUntilPayout,
            'order_ids' => $orders->pluck('id')->values(),
            'currency' => $orders->first()?->currency ?? 'MAD',
            'default_bank_account_id' => $settings->default_bank_account_id,
        ];
    }
}
