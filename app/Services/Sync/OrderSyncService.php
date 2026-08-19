<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Jobs\SendWhatsAppConfirmation;
use App\Factories\ConnectorFactory;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderSyncService
{
    /**
     * Page through all orders on the platform and persist them to the store.
     */
    public function syncFromPlatform(Store $store, PlatformConnection $connection, ?CarbonInterface $since = null): SyncLog
    {
        $log = SyncLog::create([
            'platform_connection_id' => $connection->id,
            'type'                   => 'orders',
            'status'                 => 'running',
            'started_at'             => now(),
        ]);

        $connection->update(['is_syncing' => true]);

        $processed = 0;
        $created   = 0;
        $updated   = 0;

        try {
            $connector = ConnectorFactory::make($connection);
            $page      = 1;

            do {
                $orders = $connector->getOrders(page: $page, perPage: 50, since: $since);

                foreach ($orders as $platformOrder) {
                    $order = $this->saveOrder($platformOrder, $connection);

                    // Skipped (e.g. no external id) — don't count it.
                    if ($order === null) {
                        continue;
                    }

                    $this->createOrderItems($order, $platformOrder['items'] ?? []);

                    $order->wasRecentlyCreated ? $created++ : $updated++;
                    $processed++;
                }

                $page++;
            } while (count($orders) > 0);

            $log->update([
                'status'            => 'completed',
                'completed_at'      => now(),
                'records_processed' => $processed,
                'summary'           => [
                    'created'         => $created,
                    'updated'         => $updated,
                    'total_processed' => $processed,
                ],
            ]);

            $connection->update([
                'last_synced_at'      => now(),
                'last_sync_error'     => null,
                'is_syncing'          => false,
                'synced_orders_count' => Order::where('platform_connection_id', $connection->id)->count(),
            ]);

            Log::info('Order sync completed', [
                'store'    => $store->id,
                'platform' => $connection->platform,
                'created'  => $created,
                'updated'  => $updated,
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status'            => 'failed',
                'completed_at'      => now(),
                'records_processed' => $processed,
                'error_message'     => $e->getMessage(),
                'summary'           => ['created' => $created, 'updated' => $updated],
            ]);

            $connection->update([
                'last_sync_error' => $e->getMessage(),
                'is_syncing'      => false,
            ]);

            Log::error('Order sync failed', [
                'store'    => $store->id,
                'platform' => $connection->platform,
                'error'    => $e->getMessage(),
            ]);
        }

        return $log->refresh();
    }

    /**
     * Persist a single order from normalized platform data — idempotently.
     *
     * The (platform_connection_id, platform_order_id) pair is the strict unique
     * key. A poll must never re-drive an order the SaaS already owns:
     *   - No external id      → skip (would collide onto one row).
     *   - Already in the SaaS  → refresh the volatile platform snapshot ONLY while
     *                            it is still awaiting confirmation; once the
     *                            confirmation desk has advanced it, leave it
     *                            untouched (never reset status/fulfillment_status).
     *   - New                  → create it in the awaiting-confirmation state and
     *                            fire OrderCreated + the WhatsApp confirmation.
     *
     * @return Order|null  null when the order carried no external id and was skipped
     */
    public function saveOrder(array $platformOrder, PlatformConnection $connection): ?Order
    {
        $externalId = (string) ($platformOrder['platform_id'] ?? '');

        if ($externalId === '') {
            Log::warning('Skipping synced order with empty platform_order_id', [
                'connection' => $connection->id,
                'platform'   => $connection->platform,
                'number'     => $platformOrder['number'] ?? null,
            ]);

            return null;
        }

        $existing = Order::query()
            ->where('platform_connection_id', $connection->id)
            ->where('platform_order_id', $externalId)
            ->first();

        if ($existing !== null) {
            // Only refresh volatile platform data while the order is still awaiting
            // confirmation; never touch the lifecycle columns from a poll. Anything
            // the confirmation desk has already moved on is left exactly as it is.
            if (($existing->fulfillment_status ?? FulfillmentStatus::Pending) === FulfillmentStatus::Pending) {
                $existing->update([
                    'total'          => $platformOrder['total'],
                    'currency'       => $platformOrder['currency'],
                    'customer_name'  => $platformOrder['customer_name'],
                    'customer_email' => $platformOrder['customer_email'],
                    'customer_phone' => $platformOrder['customer_phone'],
                    'items'          => $platformOrder['items'],
                    'platform_data'  => $platformOrder,
                    'synced_at'      => now(),
                ]);
            }

            return $existing;
        }

        // New order — always lands awaiting confirmation. It must NOT jump to
        // fulfillment/completed on the way in; only the confirmation step (dashboard
        // or a customer WhatsApp reply) may advance it.
        $order = Order::create([
            'store_id'               => $connection->store_id,
            'platform_connection_id' => $connection->id,
            'platform_order_id'      => $externalId,
            'order_number'           => $platformOrder['number'],
            'status'                 => OrderStatus::Pending,
            'fulfillment_status'     => FulfillmentStatus::Pending,
            'total'                  => $platformOrder['total'],
            'currency'               => $platformOrder['currency'],
            'customer_name'          => $platformOrder['customer_name'],
            'customer_email'         => $platformOrder['customer_email'],
            'customer_phone'         => $platformOrder['customer_phone'],
            'items'                  => $platformOrder['items'],
            'platform_data'          => $platformOrder,
            'synced_at'              => now(),
        ]);

        OrderCreated::dispatch($order);

        if (filled($order->customer_phone)) {
            SendWhatsAppConfirmation::dispatch($order)->delay(now()->addSeconds(5));
        }

        return $order;
    }

    /**
     * Update the order's items JSON if the order was just created and items were provided.
     * Orders store items as a JSON column; this hook exists for future expansion
     * (e.g., a dedicated order_items table).
     */
    public function createOrderItems(Order $order, array $items): void
    {
        if (!empty($items) && empty($order->items)) {
            $order->update(['items' => $items]);
        }
    }
}
