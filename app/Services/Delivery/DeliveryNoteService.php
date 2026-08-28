<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\OzonExpressConnector;
use App\Models\DeliveryConnection;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteShipment;
use App\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Orchestrates Ozon's Bon de Livraison (delivery note) flow: create, add parcels, save, get PDFs. */
class DeliveryNoteService
{
    public function create(DeliveryConnection $connection): DeliveryNote
    {
        $ref = 'BL-' . now()->format('Ymd') . '-' . Str::upper(Str::random(4));

        $connector = new OzonExpressConnector($connection);
        $result = $connector->createDeliveryNote($connection, $ref);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['delivery_note' => $result['error'] ?? 'Ozon rejected the delivery note.']);
        }

        return DeliveryNote::create([
            'store_id' => $connection->store_id,
            'delivery_connection_id' => $connection->id,
            'provider_code' => 'ozon',
            'provider_ref' => $ref,
            'status' => DeliveryNote::STATUS_DRAFT,
            'raw_payload' => $result['raw'],
        ]);
    }

    /** @param Collection<int, Shipment> $shipments */
    public function addShipments(DeliveryNote $note, Collection $shipments): DeliveryNote
    {
        $trackingNumbers = $shipments->pluck('tracking_number')->filter()->values()->all();

        if ($trackingNumbers === []) {
            throw ValidationException::withMessages(['shipments' => 'Select at least one shipment with a tracking number.']);
        }

        $connector = new OzonExpressConnector($note->connection);
        $result = $connector->addParcelsToDeliveryNote($note->connection, $note->provider_ref, $trackingNumbers);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['shipments' => $result['error'] ?? 'Ozon rejected these parcels.']);
        }

        foreach ($shipments as $shipment) {
            // Not $note->shipments()->syncWithoutDetaching() — that inserts a
            // raw pivot row and skips HasUlids' creating() hook, leaving `id`
            // null against this table's ULID primary key.
            DeliveryNoteShipment::firstOrCreate([
                'delivery_note_id' => $note->id,
                'shipment_id' => $shipment->id,
            ]);
            $shipment->update(['delivery_note_ref' => $note->provider_ref]);
        }

        return $note->refresh();
    }

    public function save(DeliveryNote $note): DeliveryNote
    {
        $connector = new OzonExpressConnector($note->connection);
        $result = $connector->saveDeliveryNote($note->connection, $note->provider_ref);

        if (! $result['ok']) {
            throw ValidationException::withMessages(['delivery_note' => $result['error'] ?? 'Ozon could not save this delivery note.']);
        }

        $urls = $connector->getDeliveryNotePdfUrls($note->provider_ref);

        $note->update([
            'status' => DeliveryNote::STATUS_SAVED,
            'pdf_url' => $urls['pdf_url'],
            'labels_pdf_url' => $urls['labels_pdf_url'],
            'saved_at' => now(),
        ]);

        return $note->refresh();
    }
}
