<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\SenditStatusMapper;
use App\Models\DeliveryConnection;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

/**
 * Applies a Sendit webhook payload to the matching shipment. Signature
 * verification happens in the controller (needs the raw request body,
 * which this service never sees) — by the time handle() runs, the payload
 * is already trusted.
 *
 * Reuses ShipmentTrackingService::apply() — the SAME primitive the polling
 * job uses — so a pushed status update is recorded and closed out
 * identically to a polled one (event + shipment row + order_shipments
 * close-out via the existing DispatchService, never a parallel writer).
 * That also gives idempotency for free: apply() is a no-op whenever the
 * shipment's provider_status/status already match what's being applied, so
 * a redelivered webhook for the same event changes nothing.
 */
class SenditWebhookService
{
    public function __construct(
        private readonly ShipmentTrackingService $tracking,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, shipment_id?: string}
     */
    public function handle(array $payload, DeliveryConnection $connection): array
    {
        return Shipment::withoutTenancy(function () use ($payload, $connection) {
            $code = (string) ($payload['code'] ?? '');

            if ($code === '') {
                return ['ok' => false, 'message' => 'Missing delivery code.'];
            }

            $shipment = Shipment::query()
                ->where('store_id', $connection->store_id)
                ->where('provider_code', 'sendit')
                ->where(function ($q) use ($code) {
                    $q->where('tracking_number', $code)->orWhere('provider_shipment_id', $code);
                })
                ->first();

            if ($shipment === null) {
                // Unknown code: never fatal — Sendit will keep retrying a
                // webhook that 4xxs, and a code we don't recognize (yet, or
                // ever) is not this connection's fault. Logged, no event
                // stored (nothing to link it to).
                Log::warning('Sendit webhook: no matching shipment for delivery code', [
                    'connection_id' => $connection->id,
                    'code' => $code,
                    'event' => $payload['event'] ?? null,
                ]);

                return ['ok' => false, 'message' => 'Unknown delivery code.'];
            }

            $newStatus = (string) ($payload['newStatus'] ?? '');

            if ($newStatus === '') {
                return ['ok' => false, 'message' => 'Missing newStatus.', 'shipment_id' => $shipment->id];
            }

            $actor = $this->tracking->systemActorFor($connection);

            if ($actor === null) {
                Log::warning('Sendit webhook: no actor could be resolved for connection.', ['connection_id' => $connection->id]);

                return ['ok' => false, 'message' => 'No actor available to apply this update.', 'shipment_id' => $shipment->id];
            }

            $normalized = SenditStatusMapper::normalize($newStatus);
            $shipment = $this->tracking->apply($shipment, $newStatus, $normalized, $payload, $actor);

            return ['ok' => true, 'message' => 'Applied.', 'shipment_id' => $shipment->id];
        });
    }
}
