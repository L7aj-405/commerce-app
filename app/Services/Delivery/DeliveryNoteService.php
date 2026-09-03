<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\OzonExpressConnector;
use App\Enums\FulfillmentDocumentStatus;
use App\Enums\FulfillmentDocumentType;
use App\Models\DeliveryConnection;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteShipment;
use App\Models\FulfillmentDocument;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Pos\DocumentGenerationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Orchestrates Ozon's Bon de Livraison (delivery note) flow: create, add parcels, save, get PDFs. */
class DeliveryNoteService
{
    public function __construct(
        private readonly FulfillmentDocumentService $documents,
        private readonly DocumentGenerationService $generator,
    ) {}

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

    /**
     * One-click dispatcher flow for a set of already-sent Ozon shipments:
     * create a fresh BL, add every parcel to it, save it, then fetch & store
     * the BL / ticket PDFs privately. If the official ticket PDF cannot be
     * fetched server-side (the Ozon PDF host may need a dashboard session),
     * a SaaS-generated fallback carrier label is stored per shipment
     * instead — never a 500.
     *
     * Reuses the existing create()/addShipments()/save() so the provider
     * calls and pivot dedup are unchanged. `addShipments()` already uses
     * DeliveryNoteShipment::firstOrCreate(), so a parcel is never added to
     * the same BL twice.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array{
     *   note: DeliveryNote,
     *   documents: Collection<int, FulfillmentDocument>,
     *   labels_fetched: bool,
     *   fallback_used: bool,
     * }
     *
     * @throws ValidationException when Ozon rejects the BL create/add/save calls
     */
    public function generateForShipments(DeliveryConnection $connection, Collection $shipments, User $actor): array
    {
        $shipments = $shipments
            ->filter(fn (Shipment $s) => $s->provider_code === 'ozon' && filled($s->tracking_number))
            ->values();

        if ($shipments->isEmpty()) {
            throw ValidationException::withMessages([
                'shipments' => 'Select at least one Ozon shipment with a tracking number.',
            ]);
        }

        $note = $this->create($connection);
        $this->addShipments($note, $shipments);
        $note = $this->save($note);

        return $this->captureDocuments($note, $shipments, $actor);
    }

    /**
     * Fetch and privately store the BL PDFs for a saved note; fall back to
     * an internal label per shipment if the ticket PDF is not fetchable.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array{note: DeliveryNote, documents: Collection<int, FulfillmentDocument>, labels_fetched: bool, fallback_used: bool}
     */
    private function captureDocuments(DeliveryNote $note, Collection $shipments, User $actor): array
    {
        $connector = new OzonExpressConnector($note->connection);
        $urls = $connector->getDeliveryNotePdfUrls($note->provider_ref);
        $dnMeta = ['dn_ref' => $note->provider_ref];
        $base = fn (string $providerCode, array $meta) => [
            'provider_code' => $providerCode,
            'generated_by' => $actor->id,
            'metadata' => array_merge($dnMeta, $meta),
        ];

        $docs = collect();

        // 1. The BL sheet — also serves as the pickup / ramassage handover note.
        $docs->push($this->documents->fetchAndStore(
            $note, FulfillmentDocumentType::DeliveryNote, $urls['pdf_url'], $base('ozon', []),
        ));

        // 2. Per-parcel ticket labels (single sheet).
        $labelDoc = $this->documents->fetchAndStore(
            $note, FulfillmentDocumentType::CarrierLabel, $urls['labels_pdf_url'],
            $base('ozon', ['variant' => 'tickets']),
        );
        $docs->push($labelDoc);

        // 3. 4-up ticket sheet — the endpoint name is unverified upstream
        //    (audit: `-4A3` vs `-4-4`). Try each candidate; keep whichever
        //    returns a real PDF, and stop trying once one succeeds.
        foreach (['labels_4x4_pdf_url', 'labels_4a3_pdf_url'] as $key) {
            if (empty($urls[$key])) {
                continue;
            }

            $fourUp = $this->documents->fetchAndStore(
                $note, FulfillmentDocumentType::CarrierLabel, $urls[$key],
                $base('ozon-4up', ['variant' => '4up', 'tried_url' => $urls[$key]]),
            );
            $docs->push($fourUp);

            if ($fourUp->status === FulfillmentDocumentStatus::Stored) {
                break;
            }
        }

        $labelsFetched = $labelDoc->status === FulfillmentDocumentStatus::Stored;
        $fallbackUsed = false;

        // 4. Fallback internal labels — only when the official ticket PDF
        //    could not be fetched. Operational continuity only; the official
        //    Ozon PDF stays preferred.
        if (! $labelsFetched) {
            foreach ($shipments as $shipment) {
                $bytes = $this->generator->renderCarrierFallbackLabel(
                    self::fallbackLabelPayload($shipment, $note),
                );

                $docs->push($this->documents->storeGeneratedPdf(
                    $shipment, FulfillmentDocumentType::FallbackLabel, $bytes,
                    [
                        'provider_code' => 'ozon',
                        'generated_by' => $actor->id,
                        'metadata' => [
                            'dn_ref' => $note->provider_ref,
                            'reason' => 'official_pdf_unavailable',
                            'official_status' => $labelDoc->status?->value,
                        ],
                    ],
                ));
            }
            $fallbackUsed = true;
        }

        return [
            'note' => $note->refresh(),
            'documents' => $docs,
            'labels_fetched' => $labelsFetched,
            'fallback_used' => $fallbackUsed,
        ];
    }

    /**
     * Everything the internal fallback carrier label prints. Pulls straight
     * from the Shipment row (already resolved at send time) so it never
     * needs the order's raw platform data.
     *
     * @return array<string, mixed>
     */
    public static function fallbackLabelPayload(Shipment $shipment, DeliveryNote $note): array
    {
        $order = $shipment->shippable;
        $store = $shipment->store;

        return [
            'provider' => 'Ozon Express',
            'tracking_number' => (string) $shipment->tracking_number,
            'bl_ref' => $note->provider_ref,
            'order_reference' => $order instanceof Order
                ? (string) ($order->order_number ?? $order->id)
                : (string) $shipment->shippable_id,
            'customer_name' => $shipment->receiver_name ?: ($order?->customer_name ?? '—'),
            'phone' => $shipment->phone ?: ($order?->customer_phone ?? ''),
            'city' => $shipment->city_name ?? '',
            'address' => $shipment->address ?? '',
            'cod_amount' => (float) $shipment->cod_amount,
            'currency' => $store?->currency ?? 'MAD',
            'sender_name' => $store?->name ?? '',
            'sender_address' => $store?->address ?? '',
            'sender_phone' => $store?->phone ?? '',
            'generated_at' => now(),
        ];
    }
}
