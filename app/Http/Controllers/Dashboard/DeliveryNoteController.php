<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeliveryConnection;
use App\Models\DeliveryNote;
use App\Models\Shipment;
use App\Services\Delivery\DeliveryNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryNoteController extends Controller
{
    public function create(Request $request, DeliveryNoteService $notes): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $connection = DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->first();

        if ($connection === null) {
            return back()->with('error', 'Connect Ozon Express before creating a delivery note.');
        }

        try {
            $note = $notes->create($connection);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', "Delivery note {$note->provider_ref} created.");
    }

    public function addShipments(Request $request, DeliveryNote $deliveryNote, DeliveryNoteService $notes): RedirectResponse
    {
        $store = $this->authorizeStore($request, $deliveryNote);

        $validated = $request->validate(['shipment_ids' => ['required', 'array', 'min:1']]);

        $shipments = Shipment::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['shipment_ids'])
            ->get();

        try {
            $notes->addShipments($deliveryNote, $shipments);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Parcels added to the delivery note.');
    }

    public function save(Request $request, DeliveryNote $deliveryNote, DeliveryNoteService $notes): RedirectResponse
    {
        $this->authorize($request, $deliveryNote);

        try {
            $notes->save($deliveryNote);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Delivery note saved.');
    }

    private function authorizeStore(Request $request, DeliveryNote $deliveryNote): \App\Models\Store
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $deliveryNote->store_id !== $store->id, 404);

        return $store;
    }
}
