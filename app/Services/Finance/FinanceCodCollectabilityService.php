<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceCodCollectabilityStatus;
use App\Enums\FinanceTransactionType;
use App\Enums\FulfillmentStatus;
use App\Models\FinanceTransaction;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Gates every COD cash action (mark collected, external settlement,
 * courier deposit) on the DELIVERY lifecycle, not just on the ledger
 * having a pending receivable. A cod_receivable_created transaction is
 * written the moment an order is CONFIRMED (see
 * FinanceOrderTransactionService::syncOrderFinancials()) — that only means
 * money is EXPECTED, never that it can already be collected. Real COD cash
 * only exists once the order has actually been delivered (whichever leg
 * carried it — internal agent or external carrier), so this is the single
 * place that answers "is this order's COD collectable right now", reading
 * the SAME fulfillment_status/shipment data the Orders/Delivery modules
 * already maintain — no new status is invented here.
 */
class FinanceCodCollectabilityService
{
    /**
     * @return array{order_id:string,is_collectable:bool,collectability_status:string,reason:string,delivery_stage:string,external_carrier:?string,internal_courier:?string}
     */
    public function assess(Order $order): array
    {
        $status = $this->status($order);
        $carriers = $this->carriers($order);

        return [
            'order_id' => $order->id,
            'is_collectable' => $status->isCollectable(),
            'collectability_status' => $status->value,
            'reason' => $status->reason(),
            'delivery_stage' => $order->fulfillment_status?->label() ?? 'Unknown',
            'external_carrier' => $carriers['external_carrier'],
            'internal_courier' => $carriers['internal_courier'],
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array{order_id:string,is_collectable:bool,collectability_status:string,reason:string,delivery_stage:string,external_carrier:?string,internal_courier:?string}>
     */
    public function assessMany(Collection $orders): Collection
    {
        return $orders->map(fn (Order $order) => $this->assess($order))->values();
    }

    public function isCollectable(Order $order): bool
    {
        return $this->status($order)->isCollectable();
    }

    public function statusOf(Order $order): FinanceCodCollectabilityStatus
    {
        return $this->status($order);
    }

    private function status(Order $order): FinanceCodCollectabilityStatus
    {
        $fulfillment = $order->fulfillment_status;

        // Cancelled/return-flow orders are never collectable, regardless of
        // what the ledger still thinks is "pending" — checked FIRST since a
        // cancelled order should never read as merely "not delivered yet".
        if ($fulfillment === FulfillmentStatus::Cancelled || $fulfillment?->isReturnFlow()) {
            return FinanceCodCollectabilityStatus::CancelledOrReturned;
        }

        // Already closed via ANY workflow (ad-hoc mark-collected, external
        // settlement, or courier deposit) — never collectable again, even
        // if fulfillment_status somehow still reads as pre-delivery.
        if ($this->alreadyClosed($order)) {
            return FinanceCodCollectabilityStatus::Settled;
        }

        $delivered = in_array($fulfillment, [FulfillmentStatus::Delivered, FulfillmentStatus::Completed], true);

        if ($delivered) {
            return FinanceCodCollectabilityStatus::DeliveredCollectable;
        }

        // Not yet delivered — still surface WHO currently has it, if known,
        // before falling back to the plain "just confirmed" state.
        $carriers = $this->carriers($order);

        if ($carriers['internal_courier'] !== null) {
            return FinanceCodCollectabilityStatus::WithInternalCourier;
        }

        if ($carriers['external_carrier'] !== null) {
            return FinanceCodCollectabilityStatus::WithExternalCarrier;
        }

        return FinanceCodCollectabilityStatus::NotDelivered;
    }

    private function alreadyClosed(Order $order): bool
    {
        return FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->whereIn('type', FinanceTransactionType::codClosingTypes())
            ->exists());
    }

    /**
     * An order is carried by AT MOST one of (external carrier via the rich
     * Shipment record, external courier via the dispatch-board's
     * OrderShipment, internal agent) — never both.
     *
     * @return array{external_carrier:?string,internal_courier:?string}
     */
    private function carriers(Order $order): array
    {
        return [
            'external_carrier' => $order->shipment?->provider?->name
                ?? $order->shipment?->provider_code
                ?? (($order->orderShipment && ! $order->orderShipment->isInternal()) ? $order->orderShipment->carrierLabel() : null),
            'internal_courier' => ($order->orderShipment && $order->orderShipment->isInternal())
                ? $order->orderShipment->carrierLabel()
                : null,
        ];
    }
}
