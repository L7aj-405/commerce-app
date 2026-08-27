<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Factories\DeliveryConnectorFactory;
use App\Models\DeliveryConnection;
use App\Models\OrderShipment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Orders\DispatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes a shipment's tracking state (any provider, via
 * DeliveryConnectorFactory) and, when it reaches a terminal state, closes
 * out the linked order_shipments row via the existing DispatchService
 * (unmodified) — which is what actually advances the order's
 * fulfillment_status through OrderWorkflowService. Also the single shared
 * primitive a provider's webhook receiver applies a pushed status through
 * (see apply()) — polling and webhooks must never diverge on how a status
 * update is recorded/closed out.
 */
class ShipmentTrackingService
{
    public function __construct(
        private readonly DispatchService $dispatch,
    ) {}

    public function refresh(Shipment $shipment, User $actor): Shipment
    {
        if ($shipment->connection === null) {
            return $shipment;
        }

        $connector = DeliveryConnectorFactory::make($shipment->connection);
        $result = $connector->trackShipment($shipment);

        if (! $result['ok']) {
            return $shipment;
        }

        return $this->apply($shipment, $result['provider_status'], $result['normalized_status'], $result['raw'], $actor);
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return array{updated: int}
     */
    public function refreshBulk(Collection $shipments, User $actor): array
    {
        $updated = 0;

        foreach ($shipments->groupBy('delivery_connection_id') as $connectionId => $group) {
            /** @var DeliveryConnection|null $connection */
            $connection = $group->first()?->connection;

            if ($connection === null) {
                continue;
            }

            $connector = DeliveryConnectorFactory::make($connection);
            $results = $connector->trackShipmentsBulk($group);

            foreach ($group as $shipment) {
                $result = $results[$shipment->tracking_number] ?? null;

                if ($result === null || ! $result['ok']) {
                    continue;
                }

                $this->apply($shipment, $result['provider_status'], $result['normalized_status'], $result['raw'], $actor);
                $updated++;
            }
        }

        return ['updated' => $updated];
    }

    /** Resolves a real User to act as for a background job with no request-bound actor. */
    public function systemActorFor(DeliveryConnection $connection): ?User
    {
        if ($connection->creator !== null) {
            return $connection->creator;
        }

        return $connection->store?->owner;
    }

    /**
     * Applies one status update to a shipment — event + shipment row +
     * order_shipments close-out, all in one transaction. Shared by both the
     * polling path above (refresh/refreshBulk) and a provider's webhook
     * receiver (see SenditWebhookService), so a pushed and a polled update
     * are recorded identically. A no-op (dedup/idempotency) whenever both
     * provider_status and normalized status are already exactly this —
     * a redelivered webhook for the same event is safe to call again.
     */
    public function apply(Shipment $shipment, string $providerStatus, string $normalizedStatus, mixed $raw, User $actor): Shipment
    {
        if ($shipment->provider_status === $providerStatus && $shipment->status === $normalizedStatus) {
            return $shipment;
        }

        return DB::transaction(function () use ($shipment, $providerStatus, $normalizedStatus, $raw, $actor) {
            ShipmentEvent::create([
                'store_id' => $shipment->store_id,
                'shipment_id' => $shipment->id,
                'provider_code' => $shipment->provider_code,
                'provider_status' => $providerStatus,
                'normalized_status' => $normalizedStatus,
                'raw_payload' => is_array($raw) ? $raw : null,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            $timestamps = match ($normalizedStatus) {
                Shipment::STATUS_DELIVERED => ['delivered_at' => now()],
                Shipment::STATUS_RETURNED, Shipment::STATUS_REFUSED => ['returned_at' => now()],
                Shipment::STATUS_CANCELLED => ['cancelled_at' => now()],
                default => [],
            };

            $shipment->update(['provider_status' => $providerStatus, 'status' => $normalizedStatus, ...$timestamps]);

            $this->closeOutOrderShipment($shipment, $normalizedStatus, $actor);

            return $shipment->refresh();
        });
    }

    private function closeOutOrderShipment(Shipment $shipment, string $normalizedStatus, User $actor): void
    {
        if ($shipment->order_shipment_id === null) {
            return;
        }

        /** @var OrderShipment|null $orderShipment */
        $orderShipment = OrderShipment::find($shipment->order_shipment_id);

        if ($orderShipment === null || $orderShipment->isClosed()) {
            return;
        }

        if ($normalizedStatus === Shipment::STATUS_DELIVERED) {
            $this->dispatch->markDelivered($orderShipment, $actor);

            return;
        }

        if (in_array($normalizedStatus, [Shipment::STATUS_RETURNED, Shipment::STATUS_REFUSED], true)) {
            $providerName = $shipment->provider?->name ?? ucfirst($shipment->provider_code);
            $this->dispatch->markFailed($orderShipment, "{$providerName}: {$normalizedStatus}.", $actor);
        }
    }
}
