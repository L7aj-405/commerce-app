<?php

declare(strict_types=1);

namespace App\Connectors\Delivery;

use App\Models\Shipment;

/**
 * Maps Ozon's raw tracking status strings to the shared normalized set.
 * Ozon's docs don't enumerate every raw value, so this is intentionally
 * conservative: only strings we can confidently classify are mapped, and
 * anything unrecognized falls back to `unknown` rather than being guessed.
 */
final class OzonStatusMapper
{
    /** @var array<string, string> */
    private const MAP = [
        'nouveau colis' => Shipment::STATUS_CREATED,
        'new' => Shipment::STATUS_CREATED,
        'created' => Shipment::STATUS_CREATED,
        'attente de ramassage' => Shipment::STATUS_AWAITING_PICKUP,
        'awaiting pickup' => Shipment::STATUS_AWAITING_PICKUP,
        'ramassé' => Shipment::STATUS_PICKED_UP,
        'ramasse' => Shipment::STATUS_PICKED_UP,
        'picked up' => Shipment::STATUS_PICKED_UP,
        'en transit' => Shipment::STATUS_IN_TRANSIT,
        'in transit' => Shipment::STATUS_IN_TRANSIT,
        'expédié' => Shipment::STATUS_IN_TRANSIT,
        'expedie' => Shipment::STATUS_IN_TRANSIT,
        'en cours de livraison' => Shipment::STATUS_OUT_FOR_DELIVERY,
        'out for delivery' => Shipment::STATUS_OUT_FOR_DELIVERY,
        'livré' => Shipment::STATUS_DELIVERED,
        'livre' => Shipment::STATUS_DELIVERED,
        'delivered' => Shipment::STATUS_DELIVERED,
        'tentative échouée' => Shipment::STATUS_FAILED_ATTEMPT,
        'tentative echouee' => Shipment::STATUS_FAILED_ATTEMPT,
        'failed attempt' => Shipment::STATUS_FAILED_ATTEMPT,
        'refusé' => Shipment::STATUS_REFUSED,
        'refuse' => Shipment::STATUS_REFUSED,
        'refused' => Shipment::STATUS_REFUSED,
        'retourné' => Shipment::STATUS_RETURNED,
        'retourne' => Shipment::STATUS_RETURNED,
        'returned' => Shipment::STATUS_RETURNED,
        'annulé' => Shipment::STATUS_CANCELLED,
        'annule' => Shipment::STATUS_CANCELLED,
        'cancelled' => Shipment::STATUS_CANCELLED,
        'canceled' => Shipment::STATUS_CANCELLED,
    ];

    public static function normalize(string $providerStatus): string
    {
        $key = mb_strtolower(trim($providerStatus));

        return self::MAP[$key] ?? Shipment::STATUS_UNKNOWN;
    }
}
