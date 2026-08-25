<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Delivery\OzonShipmentCreationException;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Delivery\ShipmentTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Sending a packed order to an external delivery provider, and refreshing its tracking. */
class DeliveryShipmentController extends Controller
{
    public function sendToOzon(Request $request, Order $order, OzonShipmentService $shipments): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $order->store_id !== $store->id, 404);

        $connection = DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->first();

        if ($connection === null) {
            return back()->with('error', 'Connect Ozon Express before sending orders to it.');
        }

        $validated = $request->validate([
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $shipment = $shipments->send($order, $connection, $validated, $request->user());
        } catch (ValidationException $e) {
            $redirect = back()->with('error', $e->validator->errors()->first());

            // A city-resolution failure carries a suggested internal match
            // (if any) — flash it separately so the UI can offer an
            // actionable "Open city mapping" link instead of just the raw
            // error text.
            if ($e->validator->errors()->has('city')) {
                $resolution = app(\App\Services\Delivery\DeliveryCityMappingResolver::class)->resolve($order, $connection);

                $redirect->with('city_issue', [
                    'raw_city' => $resolution['raw_city_text'],
                    'suggested_city_id' => $resolution['suggested_internal_city_id'],
                    'suggested_city_name' => $resolution['suggested_internal_city_name'],
                ]);
            }

            return $redirect;
        } catch (OzonShipmentCreationException $e) {
            // Ozon rejected the parcel or its response couldn't be parsed —
            // a provider-response problem, not a readiness problem. Flash
            // the safe debug details (never the api_key) so the UI can show
            // a collapsible "why" instead of just the flat error string.
            return back()->with('error', $e->getMessage())->with('shipment_issue', $e->debug);
        }

        // add-parcel returning HTTP 200 + a tracking number is not trusted
        // alone (see OzonShipmentService::send()) — a shipment that could
        // not be independently confirmed via parcel-info/tracking is a
        // WARNING, not a success: the order must stay in "awaiting carrier"
        // until a human retries verification.
        if ($shipment->status === Shipment::STATUS_PROVIDER_UNVERIFIED) {
            return back()
                ->with('warning', 'Ozon returned a tracking number, but the parcel could not be verified in Ozon. Do not hand this parcel to carrier yet.')
                ->with('shipment_verification', OzonShipmentService::verificationDebug($shipment));
        }

        return back()->with('success', "Ozon parcel created and verified. Tracking: {$shipment->tracking_number}");
    }

    public function retryVerification(Request $request, Shipment $shipment, OzonShipmentService $shipments): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $shipment->store_id !== $store->id, 404);

        try {
            $shipment = $shipments->retryVerification($shipment, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        if ($shipment->status === Shipment::STATUS_PROVIDER_UNVERIFIED) {
            return back()
                ->with('warning', 'Ozon returned a tracking number, but the parcel could not be verified in Ozon. Do not hand this parcel to carrier yet.')
                ->with('shipment_verification', OzonShipmentService::verificationDebug($shipment));
        }

        return back()->with('success', "Ozon parcel created and verified. Tracking: {$shipment->tracking_number}");
    }

    public function refreshTracking(Request $request, Shipment $shipment, ShipmentTrackingService $tracking): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $shipment->store_id !== $store->id, 404);

        $tracking->refresh($shipment, $request->user());

        return back()->with('success', 'Tracking refreshed.');
    }
}
