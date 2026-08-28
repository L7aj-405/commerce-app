<?php

declare(strict_types=1);

namespace App\Connectors\Delivery;

use App\Models\Shipment;

/**
 * Maps Sendit's documented raw status vocabulary to the shared normalized
 * set (Shipment::normalizedStatuses()) — same contract as OzonStatusMapper:
 * only confidently-classifiable strings are mapped, anything unrecognized
 * falls back to `unknown` rather than being guessed.
 *
 * Mapping rationale (per the Phase 1 spec):
 *   PENDING/TO_PREPARE/TO_PICKUP        -> awaiting_pickup (not yet handed to a courier)
 *   PICKEDUP                            -> picked_up
 *   WAREHOUSE/TRANSIT/DISTRIBUTED       -> in_transit
 *   DELIVERING                          -> out_for_delivery
 *   DELIVERED                           -> delivered
 *   CANCELED                            -> cancelled
 *   REJECTED                            -> refused (routes through the return flow, see ShipmentTrackingService)
 *   UNREACHABLE/POSTPONED               -> failed_attempt (still "in flight" for polling, but flags an exception)
 *   NEW_DESTINATION                     -> in_transit (address changed mid-route, parcel still moving)
 */
final class SenditStatusMapper
{
    /** @var array<string, string> */
    private const MAP = [
        'pending' => Shipment::STATUS_AWAITING_PICKUP,
        'to_prepare' => Shipment::STATUS_AWAITING_PICKUP,
        'to_pickup' => Shipment::STATUS_AWAITING_PICKUP,
        'new_destination' => Shipment::STATUS_IN_TRANSIT,
        'pickedup' => Shipment::STATUS_PICKED_UP,
        'warehouse' => Shipment::STATUS_IN_TRANSIT,
        'transit' => Shipment::STATUS_IN_TRANSIT,
        'distributed' => Shipment::STATUS_IN_TRANSIT,
        'delivering' => Shipment::STATUS_OUT_FOR_DELIVERY,
        'delivered' => Shipment::STATUS_DELIVERED,
        'unreachable' => Shipment::STATUS_FAILED_ATTEMPT,
        'postponed' => Shipment::STATUS_FAILED_ATTEMPT,
        'canceled' => Shipment::STATUS_CANCELLED,
        'cancelled' => Shipment::STATUS_CANCELLED,
        'rejected' => Shipment::STATUS_REFUSED,
    ];

    public static function normalize(string $providerStatus): string
    {
        $key = mb_strtolower(trim($providerStatus));

        return self::MAP[$key] ?? Shipment::STATUS_UNKNOWN;
    }
}
