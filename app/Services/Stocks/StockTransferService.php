<?php

declare(strict_types=1);

namespace App\Services\Stocks;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    /**
     * Record a stock transfer / Bon de Sortie and move the goods atomically.
     *
     * Each line decrements the locked source row (never below zero) and writes a
     * `transfer` ledger entry; a warehouse destination also increments the target
     * row and writes the matching inbound leg. A team/external destination has no
     * tracked target, so the goods simply leave the source. Everything runs in one
     * transaction — a single insufficient line rolls the whole slip back.
     *
     * @param  array<string, mixed>  $data  validated request payload
     */
    public function create(Store $store, array $data, User $actor): StockTransfer
    {
        $source = $this->resolveWarehouse($store, $data['source_warehouse_id'], 'source_warehouse_id');

        $kind = $data['destination_kind'];
        $destinationWarehouse = null;

        if ($kind === StockTransfer::KIND_WAREHOUSE) {
            $destinationWarehouse = $this->resolveWarehouse($store, $data['destination_warehouse_id'] ?? null, 'destination_warehouse_id');

            if ($destinationWarehouse->id === $source->id) {
                throw ValidationException::withMessages([
                    'destination_warehouse_id' => 'Destination must differ from the source warehouse.',
                ]);
            }
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one item to transfer.']);
        }

        return DB::transaction(function () use ($store, $data, $actor, $source, $kind, $destinationWarehouse, $items) {
            $transfer = StockTransfer::create([
                'store_id'                 => $store->id,
                'reference'                => $this->generateReference($store),
                'source_warehouse_id'      => $source->id,
                'destination_kind'         => $kind,
                'destination_warehouse_id' => $destinationWarehouse?->id,
                'destination_member_id'    => $data['destination_member_id'] ?? null,
                'destination_label'        => $kind === StockTransfer::KIND_WAREHOUSE ? null : ($data['destination_label'] ?? null),
                'responsible_member_id'    => $data['responsible_member_id'] ?? null,
                'created_by'               => $actor->id,
                'status'                   => StockTransfer::STATUS_COMPLETED,
                'transfer_date'            => Carbon::parse($data['transfer_date']),
                'notes'                    => $data['notes'] ?? null,
                'total_quantity'           => 0,
            ]);

            $destinationName = $destinationWarehouse?->name
                ?? ($data['destination_label'] ?? null)
                ?? 'external';

            $total = 0;

            foreach ($items as $i => $row) {
                $quantity = (int) $row['quantity'];
                [$product, $variant] = $this->resolveLine($store, $row, $i);

                $this->moveOut($transfer, $store, $source, $product, $variant, $quantity, $actor, $destinationName, $i);

                if ($destinationWarehouse !== null) {
                    $this->moveIn($transfer, $store, $destinationWarehouse, $product, $variant, $quantity, $actor, $source->name);
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id'        => $product->id,
                    'variant_id'        => $variant?->id,
                    'product_name'      => $product->name,
                    'variant_name'      => $variant?->getDisplayName(),
                    'sku'               => $variant?->sku ?? $product->sku,
                    'quantity'          => $quantity,
                    'unit_price'        => $variant?->price ?? $product->price,
                ]);

                $total += $quantity;
            }

            $transfer->update(['total_quantity' => $total]);

            return $transfer->fresh(['items']);
        });
    }

    /** Decrement the locked source row and log the outbound leg. */
    private function moveOut(
        StockTransfer $transfer,
        Store $store,
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        User $actor,
        string $destinationName,
        int $index,
    ): void {
        $stock = $this->lockRow($product, $variant, $warehouse);
        $before = (int) ($stock?->quantity ?? 0);

        if ($before < $quantity) {
            $label = $variant?->getDisplayName() ?? $product->name;

            throw ValidationException::withMessages([
                "items.$index.quantity" => "Only {$before} of \"{$label}\" available in {$warehouse->name}.",
            ]);
        }

        $after = $before - $quantity;
        $stock->update(['quantity' => $after]);

        $this->writeLedger($transfer, $store, $product, $variant, -$quantity, $before, $after, $actor,
            "Transfer OUT to {$destinationName}");
    }

    /** Increment (or create) the destination row and log the inbound leg. */
    private function moveIn(
        StockTransfer $transfer,
        Store $store,
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        User $actor,
        string $sourceName,
    ): void {
        $stock = $this->lockRow($product, $variant, $warehouse)
            ?? Stock::create([
                'product_id'   => $product->id,
                'variant_id'   => $variant?->id,
                'warehouse_id' => $warehouse->id,
                'quantity'     => 0,
            ]);

        $before = (int) $stock->quantity;
        $after  = $before + $quantity;
        $stock->update(['quantity' => $after]);

        $this->writeLedger($transfer, $store, $product, $variant, $quantity, $before, $after, $actor,
            "Transfer IN from {$sourceName}");
    }

    private function writeLedger(
        StockTransfer $transfer,
        Store $store,
        Product $product,
        ?ProductVariant $variant,
        int $change,
        int $before,
        int $after,
        User $actor,
        string $notes,
    ): void {
        StockLedger::create([
            'store_id'         => $store->id,
            'product_id'       => $product->id,
            'variant_id'       => $variant?->id,
            'type'             => 'transfer',
            'source_type'      => $transfer->getMorphClass(),
            'source_id'        => $transfer->id,
            'quantity_change'  => $change,
            'stock_before'     => $before,
            'stock_after'      => $after,
            'reference_number' => $transfer->reference,
            'notes'            => $notes,
            'user_id'          => $actor->id,
        ]);
    }

    /** Lock the exact stock row for update; null when it doesn't exist yet. */
    private function lockRow(Product $product, ?ProductVariant $variant, Warehouse $warehouse): ?Stock
    {
        return Stock::query()
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: Product, 1: ?ProductVariant}
     */
    private function resolveLine(Store $store, array $row, int $index): array
    {
        $product = Product::query()
            ->where('store_id', $store->id)
            ->find($row['product_id'] ?? null);

        if ($product === null) {
            throw ValidationException::withMessages([
                "items.$index.product_id" => 'Product not found in this store.',
            ]);
        }

        $variant = null;

        if (! empty($row['variant_id'])) {
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->find($row['variant_id']);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    "items.$index.variant_id" => 'Variant does not belong to the selected product.',
                ]);
            }
        } elseif ($product->isVariable()) {
            throw ValidationException::withMessages([
                "items.$index.variant_id" => 'Pick a variant for this product.',
            ]);
        }

        return [$product, $variant];
    }

    private function resolveWarehouse(Store $store, ?string $warehouseId, string $field): Warehouse
    {
        $warehouse = $warehouseId
            ? $store->warehouses()->where('warehouses.id', $warehouseId)->first()
            : null;

        if ($warehouse === null) {
            throw ValidationException::withMessages([$field => 'Choose a warehouse that belongs to this store.']);
        }

        return $warehouse;
    }

    /** BS-YYYYMMDD-0001, sequence restarting daily per store. */
    private function generateReference(Store $store): string
    {
        $date = now()->format('Ymd');

        $count = StockTransfer::withTrashed()
            ->where('store_id', $store->id)
            ->where('reference', 'like', "BS-{$date}-%")
            ->count();

        return sprintf('BS-%s-%04d', $date, $count + 1);
    }
}
