<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Models\AgentActivityEvent;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\Activity\AgentActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handing a packed order to whoever carries it, and recording the outcome.
 *
 * The shipment is bookkeeping; the order's FulfillmentStatus stays the source of
 * truth for where the order is. Dispatching does not advance the order (it is
 * already `ready_for_delivery`) — only the outcome does: delivered advances it,
 * a failed delivery routes it into the return flow, where the goods will be
 * inspected before any stock moves.
 */
class DispatchService
{
    public function __construct(
        private readonly OrderWorkflowService $workflow,
        private readonly AgentActivityRecorder $activity,
    ) {}

    /**
     * Assign a carrier to a packed order. Idempotent per order: re-assigning
     * updates the open shipment rather than creating a second one.
     *
     * @param  array{carrier_type:string, carrier_name?:?string, tracking_number?:?string,
     *               tracking_url?:?string, agent_id?:?string, manifest_reference?:?string,
     *               notes?:?string}  $data
     *
     * @throws ValidationException
     */
    public function assign(Order|PosOrder $order, array $data, User $actor): OrderShipment
    {
        $this->validateCarrier($data);

        return DB::transaction(function () use ($order, $data, $actor) {
            $store = $order->store;

            $shipment = OrderShipment::query()
                ->where('shippable_type', $order::class)
                ->where('shippable_id', $order->getKey())
                ->inFlight()
                ->lockForUpdate()
                ->first();

            $attributes = [
                'carrier_type'       => $data['carrier_type'],
                'carrier_name'       => $data['carrier_name'] ?? null,
                'tracking_number'    => $data['tracking_number'] ?? null,
                'tracking_url'       => $data['tracking_url'] ?? null,
                'agent_id'           => $data['agent_id'] ?? null,
                'manifest_reference' => $data['manifest_reference'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'status'             => OrderShipment::STATUS_DISPATCHED,
                'dispatched_by'      => $actor->id,
                'dispatched_at'      => now(),
            ];

            if ($shipment !== null) {
                $shipment->update($attributes);

                return $shipment->refresh();
            }

            $created = OrderShipment::create($attributes + [
                'store_id'         => $store->id,
                'shippable_type'   => $order::class,
                'shippable_id'     => $order->getKey(),
                'reference'        => $this->generateReference($store),
                'delivery_address' => $order->delivery_address ?? null,
                'cod_amount'       => $this->expectedCod($order),
            ]);

            // Only the FIRST assignment counts as "assigned" activity — a
            // carrier change re-uses the update() branch above and never
            // reaches here.
            if ($store !== null) {
                $this->activity->record($actor, $store, AgentActivityEvent::DELIVERY_ASSIGNED, 'delivery', [
                    'subject' => $order,
                    'order_id' => $order->getKey(),
                    'metadata' => ['carrier_type' => $data['carrier_type']],
                ]);
            }

            return $created;
        });
    }

    /**
     * Confirm the customer received it, record any cash collected, and advance
     * the order.
     */
    public function markDelivered(OrderShipment $shipment, User $actor, ?float $codCollected = null): OrderShipment
    {
        return DB::transaction(function () use ($shipment, $actor, $codCollected) {
            $shipment->update([
                'status'        => OrderShipment::STATUS_DELIVERED,
                'delivered_at'  => now(),
                // Only a COD parcel records a collection; a prepaid one keeps null.
                'cod_collected' => (float) $shipment->cod_amount > 0
                    ? ($codCollected ?? (float) $shipment->cod_amount)
                    : null,
            ]);

            $order = $shipment->shippable;

            if ($order !== null) {
                $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

                if ($current->canTransitionTo(FulfillmentStatus::Delivered)) {
                    $this->workflow->transition($order, FulfillmentStatus::Delivered, $actor);

                    if ($order->store !== null) {
                        $this->activity->record($actor, $order->store, AgentActivityEvent::DELIVERY_DELIVERED, 'delivery', [
                            'subject' => $order,
                            'order_id' => $order->getKey(),
                            'metadata' => [
                                'cod_amount' => (float) $shipment->cod_amount,
                                'cod_collected' => $shipment->cod_collected !== null ? (float) $shipment->cod_collected : null,
                            ],
                        ]);
                    }
                }
            }

            return $shipment->refresh();
        });
    }

    /**
     * The customer refused it or it could not be delivered. Routes the order
     * into the return flow — no stock moves until an inspector sees the goods.
     */
    public function markFailed(OrderShipment $shipment, string $reason, User $actor): OrderShipment
    {
        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A failed delivery needs a reason.',
            ]);
        }

        return DB::transaction(function () use ($shipment, $reason, $actor) {
            $shipment->update([
                'status'         => OrderShipment::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            $order = $shipment->shippable;

            if ($order !== null) {
                $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

                if ($current->canTransitionTo(FulfillmentStatus::Returned)) {
                    $this->workflow->transition($order, FulfillmentStatus::Returned, $actor, $reason);

                    if ($order->store !== null) {
                        $eventType = $reason === 'customer_unreachable'
                            ? AgentActivityEvent::DELIVERY_UNREACHABLE
                            : AgentActivityEvent::DELIVERY_FAILED;

                        $this->activity->record($actor, $order->store, $eventType, 'delivery', [
                            'subject' => $order,
                            'order_id' => $order->getKey(),
                            'metadata' => ['reason' => $reason],
                        ]);
                    }
                }
            }

            return $shipment->refresh();
        });
    }

    /**
     * Label today's handover to one carrier, so a batch can be printed and
     * signed for as a single manifest.
     */
    public function manifestReference(Store $store, string $carrier): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $carrier) ?: 'CARRIER');

        return sprintf('MAN-%s-%s', substr($slug, 0, 6), now()->format('Ymd'));
    }

    /**
     * The distinct manifests a store has, newest first — the picklist behind the
     * dispatch board's "print manifest" affordance.
     *
     * @return array<int, array{reference:string,carrier:string,parcels:int,pending:int}>
     */
    public function manifests(Store $store): array
    {
        return OrderShipment::query()
            ->where('store_id', $store->id)
            ->whereNotNull('manifest_reference')
            ->get()
            ->groupBy('manifest_reference')
            ->map(fn ($group) => [
                'reference' => $group->first()->manifest_reference,
                'carrier'   => $group->first()->carrierLabel(),
                'parcels'   => $group->count(),
                'pending'   => $group->whereIn('status', [
                    OrderShipment::STATUS_PENDING,
                    OrderShipment::STATUS_DISPATCHED,
                ])->count(),
            ])
            ->sortByDesc('reference')
            ->values()
            ->all();
    }

    /**
     * Everything the handover sheet prints: the carrier, the parcels in the
     * batch, and the totals both parties sign against.
     *
     * A manifest is a point-in-time document, but it is rebuilt on demand rather
     * than frozen — reprinting after a parcel is added or delivered shows the
     * batch as it stands now, which is what a re-handover needs.
     *
     * @return array{
     *   reference:string, carrier:string, carrier_type:?string, store:Store,
     *   generated_at:\Illuminate\Support\Carbon, currency:string,
     *   parcels:array<int, array<string,mixed>>, total_parcels:int, total_value:float
     * }
     *
     * @throws ValidationException when no such manifest exists for this store
     */
    public function gatherManifest(Store $store, string $reference): array
    {
        $shipments = OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('manifest_reference', $reference)
            ->with(['shippable', 'agent:id,name'])
            ->orderBy('created_at')
            ->get();

        if ($shipments->isEmpty()) {
            throw ValidationException::withMessages([
                'reference' => "No manifest [{$reference}] for this store.",
            ]);
        }

        $first    = $shipments->first();
        $parcels  = [];
        $totalVal = 0.0;

        foreach ($shipments as $i => $shipment) {
            $order = $shipment->shippable;
            $value = $this->orderValue($order);
            $totalVal += $value;

            $parcels[] = [
                'index'           => $i + 1,
                'reference'       => $shipment->reference,
                'order_reference' => $this->orderReference($order),
                'customer_name'   => $order?->customer_name ?? '—',
                'customer_phone'  => $order?->customer_phone ?? '',
                'address'         => $shipment->delivery_address ?? $order?->delivery_address ?? '—',
                'tracking_number' => $shipment->tracking_number ?? '',
                'value'           => $value,
                'status'          => $shipment->status,
            ];
        }

        return [
            'reference'     => $reference,
            'carrier'       => $first->carrierLabel(),
            'carrier_type'  => $first->carrier_type,
            'store'         => $store,
            'generated_at'  => now(),
            'currency'      => $store->currency ?? 'MAD',
            'parcels'       => $parcels,
            'total_parcels' => count($parcels),
            'total_value'   => round($totalVal, 2),
        ];
    }

    private function orderValue(Order|PosOrder|null $order): float
    {
        return match (true) {
            $order instanceof PosOrder => (float) $order->total_amount,
            $order instanceof Order    => (float) $order->total,
            default                    => 0.0,
        };
    }

    private function orderReference(Order|PosOrder|null $order): string
    {
        return match (true) {
            $order instanceof PosOrder => (string) $order->receipt_number,
            $order instanceof Order    => (string) $order->order_number,
            default                    => '—',
        };
    }

    /**
     * Cash the driver is expected to collect on delivery.
     *
     * A POS order collects only its unpaid balance — a card sale settled at the
     * counter leaves nothing to collect. An online order has no payment split
     * here, so the whole total is treated as cash on delivery, the norm for this
     * market; a store that prepays online can zero it out later.
     */
    private function expectedCod(Order|PosOrder|null $order): float
    {
        if ($order instanceof PosOrder) {
            return max(0.0, (float) $order->total_amount - (float) $order->amount_paid);
        }

        if ($order instanceof Order) {
            return (float) $order->total;
        }

        return 0.0;
    }

    /**
     * A driver's own live delivery queue: the parcels dispatched to them,
     * shaped for the mobile agent dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function agentQueue(Store $store, User $agent): array
    {
        return OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('agent_id', $agent->id)
            ->where('status', OrderShipment::STATUS_DISPATCHED)
            ->with(['shippable', 'store:id,currency'])
            ->oldest('dispatched_at')
            ->get()
            ->map(fn (OrderShipment $s) => $this->agentParcel($s))
            ->all();
    }

    /**
     * A driver's cash position for the day — what they still owe from parcels in
     * hand, and what they have already collected on delivered ones.
     *
     * @return array{outstanding:float, collected_today:float, delivered_today:int, in_queue:int}
     */
    public function agentReconciliation(Store $store, User $agent): array
    {
        $queue = OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('agent_id', $agent->id)
            ->where('status', OrderShipment::STATUS_DISPATCHED)
            ->get();

        $deliveredToday = OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('agent_id', $agent->id)
            ->where('status', OrderShipment::STATUS_DELIVERED)
            ->whereDate('delivered_at', today())
            ->get();

        return [
            'outstanding'     => round((float) $queue->sum('cod_amount'), 2),
            'collected_today' => round((float) $deliveredToday->sum('cod_collected'), 2),
            'delivered_today' => $deliveredToday->count(),
            'in_queue'        => $queue->count(),
        ];
    }

    /**
     * A driver's recently closed drops — the delivered / failed history list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function agentHistory(Store $store, User $agent, int $limit = 40): array
    {
        return OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('agent_id', $agent->id)
            ->whereIn('status', [OrderShipment::STATUS_DELIVERED, OrderShipment::STATUS_FAILED])
            ->with(['shippable', 'store:id,currency'])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (OrderShipment $s) => $this->agentParcel($s, [
                'status'         => $s->status,
                'cod_collected'  => $s->cod_collected !== null ? (float) $s->cod_collected : null,
                'failure_reason' => $s->failure_reason,
                'closed_at'      => ($s->delivered_at ?? $s->updated_at)?->toIso8601String(),
            ]))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function agentParcel(OrderShipment $shipment, array $extra = []): array
    {
        $order = $shipment->shippable;

        return [
            'id'              => $shipment->id,
            'reference'       => $shipment->reference,
            'order_reference' => $this->orderReference($order),
            'source'          => $order instanceof PosOrder ? 'pos' : 'online',
            'customer_name'   => $order?->customer_name ?? '—',
            'customer_phone'  => $order?->customer_phone ?? '',
            'address'         => $shipment->delivery_address ?? $order?->delivery_address ?? '',
            'total'           => $this->orderValue($order),
            'cod_amount'      => (float) $shipment->cod_amount,
            'dispatched_at'   => $shipment->dispatched_at?->toIso8601String(),
            'currency'        => $shipment->store?->currency ?? 'MAD',
            ...$extra,
        ];
    }

    /** @param array<string, mixed> $data */
    private function validateCarrier(array $data): void
    {
        $type = $data['carrier_type'] ?? null;

        if (! in_array($type, OrderShipment::carrierTypes(), true)) {
            throw ValidationException::withMessages([
                'carrier_type' => 'Choose a courier or an internal agent.',
            ]);
        }

        if ($type === OrderShipment::CARRIER_COURIER && blank($data['carrier_name'] ?? null)) {
            throw ValidationException::withMessages([
                'carrier_name' => 'Name the courier handling this shipment.',
            ]);
        }

        if ($type === OrderShipment::CARRIER_INTERNAL && blank($data['agent_id'] ?? null)) {
            throw ValidationException::withMessages([
                'agent_id' => 'Choose the delivery agent taking this order.',
            ]);
        }
    }

    /** SHP-YYYYMMDD-0001, sequence restarting daily per store. */
    private function generateReference(Store $store): string
    {
        $date = now()->format('Ymd');

        $count = OrderShipment::query()
            ->where('store_id', $store->id)
            ->where('reference', 'like', "SHP-{$date}-%")
            ->lockForUpdate()
            ->count();

        return sprintf('SHP-%s-%04d', $date, $count + 1);
    }
}
