<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Jobs\SendWhatsAppConfirmation;
use App\Factories\ConnectorFactory;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderSyncService
{
    /**
     * Page through all orders on the platform and persist them to the store.
     */
    public function syncFromPlatform(Store $store, PlatformConnection $connection, ?Carbon $since = null): SyncLog
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
     * Persist (create or update) a single order from normalized platform data.
     * Fires OrderCreated for newly created orders to trigger WhatsApp notifications.
     */
    public function saveOrder(array $platformOrder, PlatformConnection $connection): Order
    {
        $order = Order::updateOrCreate(
            [
                'platform_connection_id' => $connection->id,
                'platform_order_id'      => $platformOrder['platform_id'],
            ],
            [
                'store_id'       => $connection->store_id,
                'order_number'   => $platformOrder['number'],
                'status'         => OrderStatus::Pending,
                'total'          => $platformOrder['total'],
                'currency'       => $platformOrder['currency'],
                'customer_name'  => $platformOrder['customer_name'],
                'customer_email' => $platformOrder['customer_email'],
                'customer_phone' => $platformOrder['customer_phone'],
                'items'          => $platformOrder['items'],
                'platform_data'  => $platformOrder,
                'synced_at'      => now(),
            ]
        );

        if ($order->wasRecentlyCreated) {
            OrderCreated::dispatch($order);

            if (filled($order->customer_phone)) {
                SendWhatsAppConfirmation::dispatch($order)->delay(now()->addSeconds(5));
            }
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
