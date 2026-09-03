<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeliveryConnection;
use App\Models\FulfillmentDocument;
use App\Models\Shipment;
use App\Services\Delivery\DeliveryNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generating and downloading fulfilment paperwork (Ozon BL + carrier
 * labels for now). Generating or downloading a document NEVER writes a
 * finance_transaction — see FulfillmentDocument's docblock.
 */
class FulfillmentDocumentController extends Controller
{
    /**
     * One-click "Create Ozon BL / Generate labels" for a set of already-sent
     * Ozon shipments. Creates the BL, adds the parcels, saves it and stores
     * the PDFs privately — falling back to an internal label per parcel if
     * the official PDF cannot be fetched. Never 500s on a fetch failure.
     */
    public function generateOzonLabels(Request $request, DeliveryNoteService $notes): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['string'],
        ]);

        $connection = DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->first();

        if ($connection === null) {
            return back()->with('error', 'Connect Ozon Express before generating labels.');
        }

        $shipments = Shipment::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->whereIn('id', $validated['shipment_ids'])
            ->get();

        if ($shipments->isEmpty()) {
            return back()->with('error', 'No matching Ozon shipments found for this store.');
        }

        try {
            $result = $notes->generateForShipments($connection, $shipments, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        $ref = $result['note']->provider_ref;

        if ($result['fallback_used']) {
            return back()->with('warning',
                "Ozon BL {$ref} was saved, but the official label PDF could not be fetched from Ozon — "
                . 'an internal fallback label was generated for each parcel. Retry once Ozon is reachable.',
            );
        }

        return back()->with('success', "Ozon BL {$ref} saved and label PDFs stored.");
    }

    /**
     * Stream a stored fulfilment document. Tenant-scoped both by the
     * FulfillmentDocument global scope (route-model binding) and an explicit
     * store check; a row that only holds an unfetchable external URL 404s.
     */
    public function download(Request $request, FulfillmentDocument $document): StreamedResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $document->store_id !== $store->id, 404);

        abort_unless($document->is_downloadable && $document->path !== null, 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        $type = $document->document_type?->value ?? 'document';

        return Storage::disk($document->disk)->download($document->path, "{$type}-{$document->id}.pdf");
    }
}
