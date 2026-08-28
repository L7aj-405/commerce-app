<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Models\InventoryAllocation;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\User;

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
            'inventory_status' => self::inventoryStatus($o),
            'unmapped_lines'  => [], // POS lines always carry a real local product id
            ...OrderSourceSummary::present($o),
        ];
    }

    public static function online(Order $o): array
    {
        $status = $o->fulfillment_status ?? FulfillmentStatus::Pending;
        $address = \App\Support\OrderAddressSummary::extract($o);

        // shipping_city_id already set (a prior confirm attempt, or a synced
        // update) is always authoritative and never re-guessed. Otherwise,
        // try an exact match on the platform's raw city text — only when
        // that's confident do we preselect the dropdown; an unmatched raw
        // city is surfaced as-is so the agent normalizes it explicitly,
        // never silently guessed.
        $suggestedCityId = $o->shipping_city_id;
        $cityRecognized = $o->shipping_city_id !== null;
        $rawCityName = null;

        if ($suggestedCityId === null && $address['city'] !== null) {
            $matched = \App\Support\OrderAddressSummary::matchCity($address['city']);

            if ($matched !== null) {
                $suggestedCityId = $matched->id;
                $cityRecognized = true;
            } else {
                $rawCityName = $address['city'];
            }
        }

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
            'delivery_address' => $o->confirmed_shipping_address ?? $address['address1'],
            'shipping_city_id' => $o->shipping_city_id,
            'shipping_city_name' => $o->shippingCity?->name,
            // The customer's ORIGINAL address as the platform sent it —
            // read-only reference data, never edited directly. The agent
            // edits `delivery_address`/`shipping_city_id` (submitted as
            // confirmed_address/confirmed_city_id) instead.
            'original_address' => $address,
            'suggested_city_id' => $suggestedCityId,
            'city_recognized' => $cityRecognized,
            'raw_city_name' => $rawCityName,
            'total'          => (float) $o->total,
            'subtotal'       => (float) $o->total,
            'tax'            => 0.0,
            'discount'       => 0.0,
            'currency'       => $o->store?->currency ?? 'MAD',
            'items'          => $items,
            'created_at'     => $o->created_at?->toIso8601String(),
            'updated_at'     => $o->fulfillment_updated_at?->toIso8601String(),
            'allocation'      => self::allocation($o),
            'inventory_status' => self::inventoryStatus($o),
            'unmapped_lines'  => collect(\App\Support\OrderLineItems::for($o))
                ->where('unmapped', true)
                ->pluck('name')
                ->values()
                ->all(),
            ...OrderSourceSummary::present($o),
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
            'platform_connection_id' => null,
            ...OrderSourceSummary::present($o),
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
            'platform_connection_id' => $o->platform_connection_id,
            ...OrderSourceSummary::present($o),
        ];
    }


    /**
     * Claim/action-eligibility flags for the CURRENT viewer — mirrors exactly
     * the server-side gates OrderController::updateStatus()'s claim check and
     * DepartmentController::authorizePhase()/claim() enforce, so a button the
     * frontend shows as enabled can never be rejected by the backend, and the
     * backend stays the only place that actually decides authorization —
     * these flags are read-only decoration, never consulted by the real
     * authorization checks themselves.
     *
     * @return array{assigned_to: ?string, assigned_user_name: ?string, claimed_by_current_user: bool, can_claim: bool, can_confirm: bool, can_cancel: bool}
     */
    public static function claimState(Order|PosOrder $model, User $user, ?string $assignedUserName = null): array
    {
        $status = $model->fulfillment_status ?? FulfillmentStatus::Pending;
        $mine = $model->assigned_to !== null && $model->assigned_to === $user->id;
        $isPending = $status === FulfillmentStatus::Pending;

        // Same rule as OrderController::updateStatus()'s claim gate: confirming
        // or cancelling a still-pending order needs orders.manage OR the claim
        // (plus the confirmation department permission either way).
        $canActOnPending = $isPending
            && $user->can('orders.confirm')
            && ($user->can('orders.manage') || $mine);

        return [
            'assigned_to' => $model->assigned_to,
            'assigned_user_name' => $assignedUserName,
            'claimed_by_current_user' => $mine,
            // Same rule DepartmentController::authorizePhase() enforces
            // before claim() is allowed to run.
            'can_claim' => $model->assigned_to === null
                && ($user->can(DepartmentRegistry::permissionFor($status->phase())) || $user->can('orders.manage')),
            'can_confirm' => $canActOnPending,
            'can_cancel' => $canActOnPending,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function allocation(Order|PosOrder $order): ?array
    {
        $allocation = $order->inventoryAllocation;

        if ($allocation === null) {
            return null;
        }

        // Never "Waiting for transfer" just because stock is short — that
        // label is only correct once a real InventoryTransfer exists and is
        // actually in transit. WaitingStockState reads the order's own
        // reservations (+ whatever transfer they point to, if any) rather
        // than guessing from allocation.status alone.
        $waiting = \App\Support\WaitingStockState::forReservations($allocation->reservations);

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
            'notes' => $allocation->notes,
            'waiting_state' => $waiting['state'],
            'waiting_state_label' => $waiting['label'],
        ];
    }

    /**
     * Phase O7 — one plain-English label for "what is inventory doing right
     * now for this order", read straight off the allocation status (or the
     * return, once one exists) rather than re-deriving it from
     * fulfillment_status alone — the two can briefly disagree (e.g. an order
     * sits at `confirmed` while still `waiting_transfer`).
     *
     * @return array{label: string, allocation_status: ?string}
     */
    private static function inventoryStatus(Order|PosOrder $order): array
    {
        $status = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        if (in_array($status, [FulfillmentStatus::Returned, FulfillmentStatus::UnderInspection], true)) {
            return ['label' => 'Returned — awaiting inspection', 'allocation_status' => null];
        }

        if ($status === FulfillmentStatus::ReturnCompleted) {
            return ['label' => 'Return closed — see disposition', 'allocation_status' => null];
        }

        $allocation = $order->inventoryAllocation;

        if ($allocation === null) {
            return [
                'label' => $status === FulfillmentStatus::Pending ? 'Pending confirmation' : 'Not tracked (legacy order, no allocation)',
                'allocation_status' => null,
            ];
        }

        $label = match ($allocation->status) {
            InventoryAllocation::STATUS_CONSUMED => 'Consumed — dispatched',
            InventoryAllocation::STATUS_RELEASED => 'Released',
            InventoryAllocation::STATUS_WAITING_TRANSFER, InventoryAllocation::STATUS_INSUFFICIENT => 'Waiting for stock',
            InventoryAllocation::STATUS_RESERVED => 'Reserved',
            default => 'Reserved',
        };

        return ['label' => $label, 'allocation_status' => $allocation->status];
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
