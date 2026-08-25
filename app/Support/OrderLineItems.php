<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\PosOrder;
use App\Services\Inventory\OrderLineInventoryResolution;
use App\Services\Inventory\OrderLineInventoryResolver;

/**
 * One line-item shape for both order models, for code that has to touch stock.
 *
 * POS lines are rows in `pos_order_items`; online lines are a JSON blob on
 * `orders.items` whose keys vary by connector. `product_id` is nullable on
 * purpose — an online line may never have been matched to a local product, in
 * which case it can still be counted and inspected but no stock can move.
 *
 * Online lines are resolved through OrderLineInventoryResolver — the single,
 * platform-agnostic rulebook for "what local product/variant/InventoryItem
 * does this line actually mean" — so this class, WarehouseAllocationService
 * (shortage/reservation creation) and the Waiting Stock repair path can never
 * silently disagree about a line's mapping.
 *
 * OrderPresenter normalises the same two sources for display; this one keeps the
 * identifiers display never needs.
 */
class OrderLineItems
{
    /**
     * @return array<int, array{
     *     product_id: ?string, variant_id: ?string, name: string, sku: ?string,
     *     quantity: int, unit_price: float, line_total: float, unmapped: bool,
     *     inventory_item_id: ?string, mapping_source: ?string, mapping_message: ?string,
     *     external_product_id: ?string, external_variant_id: ?string,
     * }>
     */
    public static function for(Order|PosOrder $order): array
    {
        return $order instanceof PosOrder
            ? self::fromPos($order)
            : self::fromOnline($order);
    }

    /** @return array<int, array<string, mixed>> */
    private static function fromPos(PosOrder $order): array
    {
        // A POS line always carries a real local product_id (the checkout
        // form only ever submits one that exists) — never unmapped, and
        // never routed through the online resolver (no external ids to
        // resolve, and resolving/creating an InventoryItem on every read
        // here would be an unwanted side effect of a plain display read).
        return $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id ?? null,
            'name'       => (string) $item->product_name,
            'sku'        => $item->product_sku,
            'quantity'   => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'line_total' => (float) $item->line_total,
            'unmapped'   => false,
            'inventory_item_id' => null,
            'mapping_source' => OrderLineInventoryResolution::SOURCE_LOCAL,
            'mapping_message' => null,
            'external_product_id' => null,
            'external_variant_id' => null,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private static function fromOnline(Order $order): array
    {
        $items = is_array($order->items) ? $order->items : [];

        if ($items === []) {
            return [];
        }

        $order->loadMissing('store.organization');
        $organizationId = $order->store?->organization_id;
        $storeId = $order->store_id;
        $platformConnectionId = $order->platform_connection_id;
        $resolver = app(OrderLineInventoryResolver::class);

        return array_map(function (array $item) use ($resolver, $organizationId, $storeId, $platformConnectionId): array {
            $quantity = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
            $unit     = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
            $sku      = $item['sku'] ?? $item['product_sku'] ?? null;

            $rawProductId = isset($item['product_id']) && $item['product_id'] !== '' ? (string) $item['product_id'] : null;
            $rawVariantId = isset($item['variant_id']) && $item['variant_id'] !== '' ? (string) $item['variant_id'] : null;
            $hadIdentifier = $rawProductId !== null || $rawVariantId !== null || filled($sku);

            $resolution = $storeId !== null
                ? $resolver->resolve($organizationId, $storeId, $platformConnectionId, $rawProductId, $rawVariantId, $sku)
                : null;

            return [
                'product_id' => $resolution?->productId,
                'variant_id' => $resolution?->productVariantId,
                'name'       => (string) ($item['name'] ?? $item['product_name'] ?? $item['title'] ?? 'Item'),
                'sku'        => $sku,
                'quantity'   => $quantity,
                'unit_price' => $unit,
                'line_total' => (float) ($item['line_total'] ?? $item['total'] ?? $unit * $quantity),
                // True only when the platform told us this line WAS a real
                // product/variant/sku reference and the resolver could not
                // pin down WHICH local product/variant it means — covers
                // both "no local product at all" AND "resolved to a
                // variable product but no specific variant could be
                // determined" (ambiguous SKU, or a variable product with no
                // matching variant listing/SKU at all). Deliberately NOT
                // keyed on whether an InventoryItem actually got created —
                // a store with no organization yet can correctly identify
                // the product/variant but still legitimately have no
                // InventoryItem (CatalogInventoryService::forCatalog()
                // refuses without one); that's an inventory-tracking gap,
                // not an unresolved order line, and must not block
                // confirmation the way a genuinely unmapped line does.
                // Never true for a line that never carried an identifier at
                // all (e.g. a genuinely custom/service line).
                'unmapped'   => $hadIdentifier && in_array($resolution?->mappingSource, [
                    OrderLineInventoryResolution::SOURCE_UNMAPPED,
                    OrderLineInventoryResolution::SOURCE_AMBIGUOUS,
                    null,
                ], true),
                'inventory_item_id' => $resolution?->inventoryItem?->id,
                'mapping_source' => $resolution?->mappingSource,
                'mapping_message' => $resolution?->mappingMessage,
                'external_product_id' => $rawProductId,
                'external_variant_id' => $rawVariantId,
            ];
        }, $items);
    }
}
