<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceCodCollectabilityStatus;
use App\Models\DeliveryProvider;
use App\Models\DeliveryProviderFinanceSetting;
use App\Models\Order;
use App\Models\Shipment;

/**
 * Explains why a delivered, external-carrier COD order does NOT (yet) show
 * up in its provider's payout period on the External Settlements tab —
 * FinanceCodPayoutPeriodService::pendingPeriods() silently filters an order
 * out the moment any one of its requirements is missing (no Shipment, no
 * delivered_at, no provider, no fee snapshot, no payout settings), which is
 * correct for the period computation itself but must never be the end of
 * the story for the accountant looking at "why is this empty" — this is
 * the other half of that filter, read-only, one order at a time.
 *
 * Requirements mirrored here (see pendingPeriods() for the actual gate):
 *   - a real Shipment record (Order::shipment(), not order_shipments —
 *     Order::orderShipment() is the internal dispatch-board bookkeeping
 *     table and is NEVER what fee/period calculation reads from)
 *   - Shipment.delivered_at set
 *   - Shipment.status actually DELIVERED (delivered_at could technically be
 *     set without the status agreeing, if something touched the row by hand)
 *   - Shipment.provider_code matching a known DeliveryProvider
 *   - an active, COD-enabled DeliveryProviderFinanceSetting for that provider
 *   - a computed fee snapshot (Shipment.fee_calculated_at)
 *   - not already settled / not cancelled or returned
 */
class FinanceCodSettlementDiagnosticsService
{
    public function __construct(
        private readonly FinanceCodCollectabilityService $collectability,
    ) {}

    /**
     * @return array{ready: bool, reasons: array<int, string>}
     */
    public function diagnose(Order $order): array
    {
        $status = $this->collectability->statusOf($order);

        if ($status === FinanceCodCollectabilityStatus::Settled) {
            return ['ready' => false, 'reasons' => ['Order is already settled.']];
        }

        if ($status === FinanceCodCollectabilityStatus::CancelledOrReturned) {
            return ['ready' => false, 'reasons' => ['Order was cancelled or returned.']];
        }

        $shipment = $order->shipment;

        if ($shipment === null) {
            return ['ready' => false, 'reasons' => ['No external shipment found for this order.']];
        }

        $reasons = [];

        if ($shipment->delivered_at === null) {
            $reasons[] = 'Shipment has no delivered_at date.';
        }

        if ($shipment->status !== Shipment::STATUS_DELIVERED) {
            $reasons[] = 'Order is not delivered according to the shipment record.';
        }

        $provider = $shipment->provider_code !== null
            ? DeliveryProvider::query()->where('code', $shipment->provider_code)->first()
            : null;

        if ($provider === null) {
            $reasons[] = 'Shipment has no delivery provider.';
        } else {
            $organization = $order->store?->organization;
            $settings = $organization !== null
                ? DeliveryProviderFinanceSetting::query()
                    ->where('organization_id', $organization->id)
                    ->where('delivery_provider_id', $provider->id)
                    ->first()
                : null;

            if ($settings === null || ! $settings->is_active || ! $settings->is_cod_enabled) {
                $reasons[] = 'No payout settings configured for this provider.';
            }
        }

        if ($shipment->fee_calculated_at === null) {
            $reasons[] = 'Fee snapshot is missing.';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons];
    }
}
