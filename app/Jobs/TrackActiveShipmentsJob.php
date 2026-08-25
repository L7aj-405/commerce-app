<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DeliveryConnection;
use App\Models\Shipment;
use App\Services\Delivery\ShipmentTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Scheduled poll of every non-terminal Ozon shipment, grouped by connection for bulk tracking. */
class TrackActiveShipmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ShipmentTrackingService $tracking): void
    {
        Shipment::withoutTenancy(function () use ($tracking) {
            Shipment::query()
                ->active()
                ->whereNotNull('delivery_connection_id')
                ->with('connection')
                ->get()
                ->groupBy('delivery_connection_id')
                ->each(function ($shipments, $connectionId) use ($tracking) {
                    /** @var DeliveryConnection|null $connection */
                    $connection = $shipments->first()?->connection;
                    $actor = $connection !== null ? $tracking->systemActorFor($connection) : null;

                    if ($connection === null || $actor === null) {
                        Log::warning('Skipping Ozon tracking sync — no actor could be resolved for connection.', [
                            'delivery_connection_id' => $connectionId,
                        ]);

                        return;
                    }

                    $tracking->refreshBulk($shipments, $actor);
                });
        });
    }
}
