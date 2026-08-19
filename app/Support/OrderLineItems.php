<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;

/**
 * One line-item shape for both order models, for code that has to touch stock.
 *
 * POS lines are rows in `pos_order_items`; online lines are a JSON blob on
 * `orders.items` whose keys vary by connector. `product_id` is nullable on
 * purpose — an online line may never have been matched to a local product, in
 * which case it can still be counted and inspected but no stock can move.
 *
 * OrderPresenter normalises the same two sources for display; this one keeps the
 * identifiers display never needs.
 */
class OrderLineItems
{
    /**
     * @return array<int, array{
     *     product_id: ?string, variant_id: ?string, name: string, sku: ?string,
     *     quantity: int, unit_price: float, line_total: float
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
        return $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id ?? null,
            'name'       => (string) $item->product_name,
            'sku'        => $item->product_sku,
            'quantity'   => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'line_total' => (float) $item->line_total,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private static function fromOnline(Order $order): array
    {
        $items = is_array($order->items) ? $order->items : [];

        if ($items === []) {
            return [];
        }

        // Online line items reference products by the PLATFORM's identifiers (e.g.
        // a WooCommerce product id), not our local ULIDs. Resolve them to local
        // ids so stock movements hit the right rows; anything we don't stock
        // locally resolves to null and simply moves no stock (see the class
        // docblock and StockMovementWriter::move()).
        $rawProductIds = self::pluckIds($items, 'product_id');
        $rawVariantIds = self::pluckIds($items, 'variant_id');

        $productMap = self::localProductMap($order, $rawProductIds);
        $variantMap = self::localVariantMap($order, $rawVariantIds);

        return array_map(function (array $item) use ($productMap, $variantMap): array {
            $quantity = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
            $unit     = (float) ($item['unit_price'] ?? $item['price'] ?? 0);

            $rawProductId = isset($item['product_id']) ? (string) $item['product_id'] : null;
            $rawVariantId = isset($item['variant_id']) ? (string) $item['variant_id'] : null;

            // A matched variant carries its own (local) product_id, which wins.
            $variant        = $rawVariantId !== null ? ($variantMap[$rawVariantId] ?? null) : null;
            $localVariantId = $variant['id'] ?? null;
            $localProductId = $variant['product_id']
                ?? ($rawProductId !== null ? ($productMap[$rawProductId] ?? null) : null);

            return [
                'product_id' => $localProductId,
                'variant_id' => $localVariantId,
                'name'       => (string) ($item['name'] ?? $item['product_name'] ?? $item['title'] ?? 'Item'),
                'sku'        => $item['sku'] ?? $item['product_sku'] ?? null,
                'quantity'   => $quantity,
                'unit_price' => $unit,
                'line_total' => (float) ($item['line_total'] ?? $item['total'] ?? $unit * $quantity),
            ];
        }, $items);
    }

    /**
     * Collect the distinct non-empty values of one key across all line items.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private static function pluckIds(array $items, string $key): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (isset($item[$key]) && $item[$key] !== '') {
                $ids[] = (string) $item[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Map each raw product identifier (platform external_id OR an already-local
     * ULID) to the local product ULID, scoped to the order's store.
     *
     * @param  array<int, string>  $rawIds
     * @return array<string, string>
     */
    private static function localProductMap(Order $order, array $rawIds): array
    {
        if ($rawIds === []) {
            return [];
        }

        $map = [];

        // Local ULIDs are always valid identifiers inside the order's Store.
        Product::withoutTenancy(fn () => Product::query()
            ->where('store_id', $order->store_id)
            ->whereIn('id', $rawIds)
            ->get(['id']))
            ->each(fn (Product $product) => $map[(string) $product->id] = $product->id);

        if ($order->platform_connection_id !== null) {
            ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()
                ->where('platform_connection_id', $order->platform_connection_id)
                ->whereIn('external_product_id', $rawIds)
                ->get(['product_id', 'external_product_id']))
                ->each(function (ProductChannelListing $listing) use (&$map): void {
                    $map[(string) $listing->external_product_id] = $listing->product_id;
                });

            // Compatibility for pre-listing orders whose product mapping could not
            // be backfilled. Restrict the fallback to the order's exact platform.
            $platform = $order->platformConnection?->platform;
            if ($platform !== null) {
                Product::withoutTenancy(fn () => Product::query()
                    ->where('store_id', $order->store_id)
                    ->where('platform', $platform)
                    ->whereIn('external_id', $rawIds)
                    ->get(['id', 'external_id']))
                    ->each(function (Product $product) use (&$map): void {
                        if (! empty($product->external_id) && ! isset($map[(string) $product->external_id])) {
                            $map[(string) $product->external_id] = $product->id;
                        }
                    });
            }
        } else {
            Product::withoutTenancy(fn () => Product::query()
                ->where('store_id', $order->store_id)
                ->whereIn('external_id', $rawIds)
                ->get(['id', 'external_id']))
                ->each(function (Product $product) use (&$map): void {
                    if (! empty($product->external_id)) {
                        $map[(string) $product->external_id] = $product->id;
                    }
                });
        }

        return $map;
    }

    /**
     * Map each raw variant identifier (platform external_id OR a local ULID) to
     * its local ids, scoped to the order's store.
     *
     * @param  array<int, string>  $rawIds
     * @return array<string, array{id: string, product_id: string}>
     */
    private static function localVariantMap(Order $order, array $rawIds): array
    {
        if ($rawIds === []) {
            return [];
        }

        $map = [];

        ProductVariant::withoutTenancy(fn () => ProductVariant::query()
            ->whereHas('product', fn ($query) => $query->where('store_id', $order->store_id))
            ->whereIn('id', $rawIds)
            ->get(['id', 'product_id']))
            ->each(function (ProductVariant $variant) use (&$map): void {
                $map[(string) $variant->id] = ['id' => $variant->id, 'product_id' => $variant->product_id];
            });

        if ($order->platform_connection_id !== null) {
            ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()
                ->where('platform_connection_id', $order->platform_connection_id)
                ->whereIn('external_variant_id', $rawIds)
                ->get(['product_id', 'product_variant_id', 'external_variant_id']))
                ->each(function (ProductVariantChannelListing $listing) use (&$map): void {
                    $map[(string) $listing->external_variant_id] = [
                        'id' => $listing->product_variant_id,
                        'product_id' => $listing->product_id,
                    ];
                });

            $platform = $order->platformConnection?->platform;
            if ($platform !== null) {
                ProductVariant::withoutTenancy(fn () => ProductVariant::query()
                    ->whereHas('product', fn ($query) => $query
                        ->where('store_id', $order->store_id)
                        ->where('platform', $platform))
                    ->whereIn('external_id', $rawIds)
                    ->get(['id', 'product_id', 'external_id']))
                    ->each(function (ProductVariant $variant) use (&$map): void {
                        if (! empty($variant->external_id) && ! isset($map[(string) $variant->external_id])) {
                            $map[(string) $variant->external_id] = [
                                'id' => $variant->id,
                                'product_id' => $variant->product_id,
                            ];
                        }
                    });
            }
        } else {
            ProductVariant::withoutTenancy(fn () => ProductVariant::query()
                ->whereHas('product', fn ($query) => $query->where('store_id', $order->store_id))
                ->whereIn('external_id', $rawIds)
                ->get(['id', 'product_id', 'external_id']))
                ->each(function (ProductVariant $variant) use (&$map): void {
                    if (! empty($variant->external_id)) {
                        $map[(string) $variant->external_id] = [
                            'id' => $variant->id,
                            'product_id' => $variant->product_id,
                        ];
                    }
                });
        }

        return $map;
    }
}
