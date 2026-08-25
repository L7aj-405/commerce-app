<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * Contract every delivery provider connector implements (Ozon Express first).
 * Every method returns a plain array shaped {ok: bool, ...} — connectors
 * never throw for ordinary API/business failures, only for programmer
 * errors (e.g. a misconfigured connection). Callers persist raw responses
 * themselves; a connector never talks to the database.
 */
interface DeliveryProviderConnectorInterface
{
    /** @return array{ok: bool, message: string, raw?: mixed} */
    public function testConnection(): array;

    /** @return array{ok: bool, cities: array<int, array{provider_city_id: string, city_name: string, raw: mixed}>, error?: string} */
    public function listCities(): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, tracking_number?: string, provider_shipment_id?: string, raw: mixed, error?: string}
     */
    public function createShipment(Order|PosOrder $order, DeliveryConnection $connection, array $options = []): array;

    /** @return array{ok: bool, raw: mixed, error?: string} */
    public function getShipmentInfo(Shipment $shipment): array;

    /** @return array{ok: bool, provider_status?: string, normalized_status?: string, raw: mixed, error?: string} */
    public function trackShipment(Shipment $shipment): array;

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return array<string, array{ok: bool, provider_status?: string, normalized_status?: string, raw: mixed, error?: string}> keyed by tracking_number
     */
    public function trackShipmentsBulk(Collection $shipments): array;

    /** @return array{ok: bool, provider_ref?: string, raw: mixed, error?: string} */
    public function createDeliveryNote(DeliveryConnection $connection, string $ref): array;

    /**
     * @param  array<int, string>  $trackingNumbers
     * @return array{ok: bool, raw: mixed, error?: string}
     */
    public function addParcelsToDeliveryNote(DeliveryConnection $connection, string $ref, array $trackingNumbers): array;

    /** @return array{ok: bool, raw: mixed, error?: string} */
    public function saveDeliveryNote(DeliveryConnection $connection, string $ref): array;

    /** @return array{pdf_url: string, labels_pdf_url: string, labels_4a3_pdf_url: string} */
    public function getDeliveryNotePdfUrls(string $ref): array;

    /** Maps a provider's raw status string to one of Shipment::normalizedStatuses(); falls back to STATUS_UNKNOWN. */
    public function normalizeStatus(string $providerStatus): string;
}
