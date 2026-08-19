<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\FulfillmentType;
use App\Jobs\SyncInventoryToWebhooks;
use App\Models\InventoryAdjustment;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderProcessingService
{
    /**
     * Create a POS order with its line items. Runs in a single transaction so
     * a failure half-way through leaves no orphan rows.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrder(Store $store, User $cashier, array $data): PosOrder
    {
        $this->assertRequiredKeys($data);

        $session = $this->resolveOpenSession($store, $cashier, $data);

        // Fulfillment type decides the state the order is born in: an instant
        // sale is completed on the spot, a delivery order lands on the board as
        // pending for the warehouse team to work.
        $fulfillmentType = FulfillmentType::tryFrom((string) ($data['fulfillment_type'] ?? '')) ?? FulfillmentType::Instant;

        try {
            return DB::transaction(function () use ($store, $cashier, $session, $data, $fulfillmentType) {
                $order = PosOrder::create([
                    'store_id'         => $store->id,
                    'pos_session_id'   => $session->id,
                    'cashier_id'       => $cashier->id,
                    'receipt_number'   => $this->generateReceiptNumber($store),
                    'status'                 => $fulfillmentType->initialStatus(),
                    'fulfillment_status'     => $fulfillmentType->initialFulfillmentStatus(),
                    'fulfillment_updated_at' => now(),
                    'subtotal'        => $data['subtotal'] ?? 0,
                    'tax_amount'      => $data['tax_amount'] ?? 0,
                    'discount_amount' => $data['discount_amount'] ?? 0,
                    'total_amount'    => $data['total_amount'] ?? 0,
                    'payment_method'  => $data['payment_method'] ?? 'cash',
                    'amount_paid'     => $data['amount_paid'] ?? 0,
                    'change_amount'   => $data['change_amount'] ?? 0,
                    'customer_name'   => $data['customer_name'] ?? null,
                    'customer_phone'  => $data['customer_phone'] ?? null,
                    'customer_email'  => $data['customer_email'] ?? null,
                    'customer_type'   => $data['customer_type'] ?? 'individual',
                    'company_name'    => $data['company_name'] ?? null,
                    'tax_id'          => $data['tax_id'] ?? null,
                    'notes'           => $data['notes'] ?? null,
                    'delivery_address' => $fulfillmentType->isDelivery() ? ($data['delivery_address'] ?? null) : null,
                    // A shipping fee only applies to a delivery order; an instant
                    // sale is always 0. total_amount already includes it (charged
                    // at checkout) — this column keeps it as its own line.
                    'delivery_fee'    => $fulfillmentType->isDelivery() ? ($data['delivery_fee'] ?? 0) : 0,
                ]);

                foreach ($data['items'] as $item) {
                    PosOrderItem::create([
                        'pos_order_id'     => $order->id,
                        'product_id'       => $item['product_id'],
                        'variant_id'       => $item['variant_id'] ?? null,
                        'product_name'     => $item['product_name'] ?? '',
                        'product_sku'      => $item['product_sku'] ?? '',
                        'unit_price'       => $item['unit_price'] ?? 0,
                        'subtotal'         => $item['subtotal'] ?? 0,
                        'line_total'       => $item['line_total'] ?? 0,
                        'quantity'         => $item['quantity'] ?? 1,
                        'discount_percent' => $item['discount_percent'] ?? 0,
                        'discount_amount'  => $item['discount_amount'] ?? 0,
                        'tax_percent'      => $item['tax_percent'] ?? 0,
                        'tax_amount'       => $item['tax_amount'] ?? 0,
                    ]);
                }

                $session->increment('total_sales', (float) ($data['total_amount'] ?? 0));
                $session->increment('total_transactions');

                Log::info('POS order created', [
                    'order_id'       => $order->id,
                    'receipt_number' => $order->receipt_number,
                    'store_id'       => $store->id,
                    'cashier_id'     => $cashier->id,
                    'total'          => $order->total_amount,
                    'items'          => count($data['items']),
                ]);

                return $order->fresh('items');
            });
        } catch (ValidationException|RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('POS order creation failed', [
                'store_id'   => $store->id,
                'cashier_id' => $cashier->id,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to create order: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrement stock for each line item and write an InventoryAdjustment audit row.
     * Polymorphic `adjustable` is set to the PosOrder so the trail is reconstructable.
     */
    public function adjustInventory(PosOrder $order): void
    {
        $warehouse = $order->store->getPrimaryWarehouse() ?? $order->store->getDefaultWarehouse();

        if ($warehouse === null) {
            Log::warning('No warehouse for store; skipping inventory adjustment', [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
            ]);

            return;
        }

        DB::transaction(function () use ($order, $warehouse) {
            foreach ($order->items as $item) {
                try {
                    // Decrement the exact variant's stock row when the line carries
                    // a variant_id; simple products keep the variant_id => null row.
                    $stock = Stock::firstOrCreate(
                        [
                            'product_id'   => $item->product_id,
                            'variant_id'   => $item->variant_id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        ['quantity' => 0]
                    );

                    $before = (int) $stock->quantity;
                    $change = -1 * (int) $item->quantity;
                    $after  = $before + $change;

                    $stock->update(['quantity' => $after]);

                    InventoryAdjustment::create([
                        'store_id'         => $order->store_id,
                        'product_id'       => $item->product_id,
                        'source'           => 'pos_sale',
                        'adjustable_type'  => PosOrder::class,
                        'adjustable_id'    => $order->id,
                        'quantity_change'  => $change,
                        'quantity_before'  => $before,
                        'quantity_after'   => $after,
                        'sync_status'      => 'pending',
                        'notes'            => "POS sale {$order->receipt_number}",
                    ]);
                } catch (Throwable $e) {
                    Log::warning('Inventory adjustment failed for line item', [
                        'order_id'   => $order->id,
                        'product_id' => $item->product_id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Dispatch one webhook-sync job per InventoryAdjustment row tied to this order.
     */
    public function queueInventorySyncToWebhooks(Store $store, PosOrder $order): void
    {
        $adjustments = InventoryAdjustment::query()
            ->where('store_id', $store->id)
            ->where('adjustable_type', PosOrder::class)
            ->where('adjustable_id', $order->id)
            ->where('sync_status', 'pending')
            ->get();

        foreach ($adjustments as $adjustment) {
            try {
                SyncInventoryToWebhooks::dispatch($store, $adjustment);
            } catch (Throwable $e) {
                Log::warning('Failed to queue webhook sync', [
                    'adjustment_id' => $adjustment->id,
                    'order_id'      => $order->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Format: {STORE_PREFIX}-{YYYYMMDD}-{0001} where the sequence resets daily per store.
     */
    private function generateReceiptNumber(Store $store): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $store->name ?? 'POS'), 0, 4) ?: 'POS');
        $date   = now()->format('Ymd');

        return DB::transaction(function () use ($store, $prefix, $date) {
            $count = PosOrder::query()
                ->where('store_id', $store->id)
                ->whereDate('created_at', now()->toDateString())
                ->lockForUpdate()
                ->count();

            $sequence = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

            return "{$prefix}-{$date}-{$sequence}";
        });
    }

    private function resolveOpenSession(Store $store, User $cashier, array $data): PosSession
    {
        $session = null;

        if (! empty($data['pos_session_id'])) {
            $session = PosSession::find($data['pos_session_id']);
        }

        $session ??= PosSession::query()
            ->where('store_id', $store->id)
            ->where('cashier_id', $cashier->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if ($session === null) {
            throw new RuntimeException('No open POS session for this cashier.');
        }

        return $session;
    }

    private function assertRequiredKeys(array $data): void
    {
        if (empty($data['items']) || ! is_array($data['items'])) {
            throw ValidationException::withMessages(['items' => 'Order must include at least one item.']);
        }

        if (! isset($data['total_amount']) || ! is_numeric($data['total_amount'])) {
            throw ValidationException::withMessages(['total_amount' => 'total_amount is required.']);
        }

        if (! isset($data['payment_method'])) {
            throw ValidationException::withMessages(['payment_method' => 'payment_method is required.']);
        }
    }
}
