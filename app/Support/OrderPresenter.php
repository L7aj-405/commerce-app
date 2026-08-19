<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\PosOrder;

/**
 * Normalizes POS and online orders into one shape for the Order Management view,
 * so the React table/drawer never has to know which model a row came from.
 */
class OrderPresenter
{
    public static function pos(PosOrder $o): array
    {
        $status     = $o->fulfillment_status ?? FulfillmentStatus::Completed;
        $isDelivery = $o->status === 'pending_delivery';

        return [
            'type'           => 'pos',
            'id'             => $o->id,
            'reference'      => $o->receipt_number,
            // Source is the sales CHANNEL and nothing else — a pos_orders row is
            // always the POS. Whether it's an instant pickup or a delivery order
            // is a fulfillment concern (see fulfillment_* below) owned by the
            // status workflow; it must never leak into the source column.
            'source'         => 'pos',
            'source_label'   => 'Direct POS',
            'is_delivery'       => $isDelivery,
            'fulfillment_type'  => $isDelivery ? 'delivery' : 'instant',
            'fulfillment_label' => $isDelivery ? 'Delivery / later' : 'Instant pickup',
            'status'         => $status->value,
            'phase'          => $status->phase(),
            'status_label'   => $status->label(),
            'transitions'    => self::transitions($status),
            'customer_name'  => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'customer_email' => $o->customer_email,
            'payment_method' => $o->payment_method,
            'delivery_address' => $o->delivery_address,
            'shipping_city_id' => $o->shipping_city_id,
            'shipping_city_name' => $o->shippingCity?->name,
            'total'          => (float) $o->total_amount,
            'subtotal'       => (float) $o->subtotal,
            'tax'            => (float) $o->tax_amount,
            'discount'       => (float) $o->discount_amount,
            'currency'       => $o->store?->currency ?? 'MAD',
            'items'          => $o->items->map(fn ($it) => [
                'name'       => $it->product_name,
                'sku'        => $it->product_sku,
                'quantity'   => (float) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'line_total' => (float) $it->line_total,
            ])->all(),
            'created_at'     => $o->created_at?->toIso8601String(),
            'updated_at'     => $o->fulfillment_updated_at?->toIso8601String(),
            'allocation'      => self::allocation($o),
        ];
    }

    public static function online(Order $o): array
    {
        $status = $o->fulfillment_status ?? FulfillmentStatus::Pending;

        $items = collect($o->items ?? [])->map(function ($it) {
            $unit = (float) ($it['unit_price'] ?? $it['price'] ?? 0);
            $qty  = (float) ($it['quantity'] ?? 1);
            return [
                'name'       => $it['name'] ?? $it['product_name'] ?? 'Item',
                'sku'        => $it['sku'] ?? $it['product_sku'] ?? null,
                'quantity'   => $qty,
                'unit_price' => $unit,
                'line_total' => (float) ($it['line_total'] ?? $it['total'] ?? $unit * $qty),
            ];
        })->all();

        return [
            'type'           => 'online',
            'id'             => $o->id,
            'reference'      => $o->order_number,
            'source'         => 'online',
            'source_label'   => 'Online store',
            'is_delivery'       => true,
            'fulfillment_type'  => 'delivery',
            'fulfillment_label' => 'Online delivery',
            'status'         => $status->value,
            'phase'          => $status->phase(),
            'status_label'   => $status->label(),
            'transitions'    => self::transitions($status),
            'customer_name'  => $o->customer_name,
            'customer_phone' => $o->customer_phone,
            'customer_email' => $o->customer_email,
            'payment_method' => null,
            'delivery_address' => $o->confirmed_shipping_address ?? data_get($o->platform_data, 'shipping.address_1'),
            'shipping_city_id' => $o->shipping_city_id,
            'shipping_city_name' => $o->shippingCity?->name,
            'total'          => (float) $o->total,
            'subtotal'       => (float) $o->total,
            'tax'            => 0.0,
            'discount'       => 0.0,
            'currency'       => $o->store?->currency ?? 'MAD',
            'items'          => $items,
            'created_at'     => $o->created_at?->toIso8601String(),
            'updated_at'     => $o->fulfillment_updated_at?->toIso8601String(),
            'allocation'      => self::allocation($o),
        ];
    }

    /**
     * Compact row for the unified orders list and the dashboard "recent orders"
     * widget. Both channels collapse to the same shape — an `origin` flag plus
     * ready-made view/receipt URLs — so the React tables never branch on model.
     */
    public static function posRow(PosOrder $o): array
    {
        $status = $o->fulfillment_status ?? FulfillmentStatus::Completed;

        return [
            'id'             => $o->id,
            'origin'         => 'pos',
            'origin_label'   => 'POS',
            'reference'      => $o->receipt_number,
            'customer_name'  => $o->customer_name,
            'customer_email' => $o->customer_email,
            'total'          => (float) $o->total_amount,
            'payment_method' => $o->payment_method,
            'status'         => $status->value,
            'status_label'   => $status->label(),
            'created_at'     => $o->created_at?->toIso8601String(),
            'view_url'       => "/dashboard/orders/{$o->receipt_number}",
            'receipt_url'    => "/dashboard/orders/{$o->receipt_number}/receipt",
        ];
    }

    public static function onlineRow(Order $o): array
    {
        $status = $o->fulfillment_status ?? FulfillmentStatus::Pending;

        return [
            'id'             => $o->id,
            'origin'         => 'online',
            'origin_label'   => 'Online',
            'reference'      => $o->order_number,
            'customer_name'  => $o->customer_name,
            'customer_email' => $o->customer_email,
            'total'          => (float) $o->total,
            'payment_method' => null,
            'status'         => $status->value,
            'status_label'   => $status->label(),
            'created_at'     => $o->created_at?->toIso8601String(),
            'view_url'       => "/dashboard/orders/online/{$o->id}",
            'receipt_url'    => "/dashboard/orders/online/{$o->id}/receipt",
        ];
    }


    /** @return array<string,mixed>|null */
    private static function allocation(Order|PosOrder $order): ?array
    {
        $allocation = $order->inventoryAllocation;

        if ($allocation === null) {
            return null;
        }

        return [
            'id' => $allocation->id,
            'status' => $allocation->status,
            'strategy' => $allocation->strategy,
            'fill_ratio' => (float) $allocation->fill_ratio,
            'warehouse_id' => $allocation->warehouse_id,
            'warehouse_name' => $allocation->warehouse?->name,
            'city_id' => $allocation->city_id,
            'city_name' => $allocation->city?->name,
            'shortage_quantity' => (int) $allocation->reservations->sum('shortage_quantity'),
        ];
    }

    /**
     * Only legal next-states are emitted, so the drawer physically cannot offer
     * an illegal move. `requires_reason` and `permission` come from the enum too
     * — the UI must not re-derive which moves need justifying.
     *
     * @return array<int, array{value:string,label:string,action:string,requires_reason:bool,permission:string}>
     */
    private static function transitions(FulfillmentStatus $status): array
    {
        return array_map(fn (FulfillmentStatus $t) => [
            'value'           => $t->value,
            'label'           => $t->label(),
            'action'          => $t->actionLabel(),
            'requires_reason' => $t->requiresReason(),
            'permission'      => $t->permission($status),
        ], $status->transitions());
    }
}
