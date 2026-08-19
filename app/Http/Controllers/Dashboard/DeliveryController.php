<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\OrderShipment;
use App\Services\Orders\DispatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The internal delivery agent's own dashboard — a driver's phone, not a manager's
 * desk. Everything here is scoped hard to the shipments dispatched to the signed-in
 * agent; a driver never sees another driver's queue or the logistics board.
 *
 * Managers dispatch and reconcile from DepartmentController; this is the far end
 * of that handoff.
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DispatchService $dispatch,
    ) {}

    public function index(Request $request): Response
    {
        $user  = $request->user();
        $store = $user->getActiveStore();

        if ($store === null) {
            return Inertia::render('Delivery/DeliveryAgentView', [
                'store' => null, 'agent' => ['name' => $user->name],
                'deliveries' => [], 'history' => [], 'reconciliation' => null,
            ]);
        }

        return Inertia::render('Delivery/DeliveryAgentView', [
            'store'          => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'agent'          => ['name' => $user->name],
            'deliveries'     => $this->dispatch->agentQueue($store, $user),
            'history'        => $this->dispatch->agentHistory($store, $user),
            'reconciliation' => $this->dispatch->agentReconciliation($store, $user),
        ]);
    }

    public function delivered(Request $request, string $shipmentId): RedirectResponse
    {
        $shipment = $this->resolveOwn($request, $shipmentId);

        $validated = $request->validate([
            'cod_collected' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->dispatch->markDelivered(
            $shipment,
            $request->user(),
            isset($validated['cod_collected']) ? (float) $validated['cod_collected'] : null,
        );

        return back()->with('success', 'Delivery confirmed.');
    }

    public function failed(Request $request, string $shipmentId): RedirectResponse
    {
        $shipment = $this->resolveOwn($request, $shipmentId);

        $validated = $request->validate([
            // Same vocabulary the returns queue understands, so a failed drop
            // lands in reverse logistics with a meaningful reason.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->dispatch->markFailed($shipment, $validated['reason'], $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('warning', 'Marked as failed — the order is now awaiting inspection.');
    }

    /**
     * Load a shipment only if it is dispatched to THIS driver in THEIR active
     * store. Anything else 404s — a driver cannot touch a parcel that is not
     * theirs, and cross-store access never resolves.
     */
    private function resolveOwn(Request $request, string $shipmentId): OrderShipment
    {
        $user  = $request->user();
        $store = $user->getActiveStore();
        abort_if($store === null, 403);

        return OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('agent_id', $user->id)
            ->where('status', OrderShipment::STATUS_DISPATCHED)
            ->findOrFail($shipmentId);
    }
}
