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
use App\Services\Inventory\WarehouseAllocationService;
use App\Support\OrderSourceSummary;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderSyncService
{
    public function __construct(private readonly WarehouseAllocationService $allocations) {}

    /**
     * Page through orders on the platform and persist them to the store.
     * $since === null means "no lower bound" — the caller decides whether
     * that's appropriate (a genuine full resync) or whether it should have
     * resolved a default range first; this method itself applies no default.
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
        $skipped   = 0;
        $failed    = 0;

        try {
            $connector = ConnectorFactory::make($connection);
            $page      = 1;

            do {
                $orders = $connector->getOrders(page: $page, perPage: 50, since: $since);

                foreach ($orders as $platformOrder) {
                    // One bad order (a mapping quirk, a per-line inventory
                    // hiccup) must never abort the whole page/sync — the
                    // rest of the batch still needs to land, and the caller
                    // needs an accurate failed_count rather than a single
                    // opaque "sync failed" for what was mostly a success.
                    try {
                        $order = $this->saveOrder($platformOrder, $connection);

                        if ($order === null) {
                            $skipped++;

                            continue;
                        }

                        $this->createOrderItems($order, $platformOrder['items'] ?? []);

                        $order->wasRecentlyCreated ? $created++ : $updated++;
                        $processed++;
                    } catch (Throwable $e) {
                        $failed++;

                        Log::warning('Order sync: one order failed to import, continuing', [
                            'store'       => $store->id,
                            'platform'    => $connection->platform,
                            'platform_id' => $platformOrder['platform_id'] ?? null,
                            'error'       => $e->getMessage(),
                        ]);
                    }
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
                    'skipped'         => $skipped,
                    'failed'          => $failed,
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
                'skipped'  => $skipped,
                'failed'   => $failed,
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status'            => 'failed',
                'completed_at'      => now(),
                'records_processed' => $processed,
                'error_message'     => $e->getMessage(),
                'summary'           => ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed],
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
                    // The RAW platform payload — BaseConnector::normalizeOrder()
                    // already puts it under this same key, so storing
                    // $platformOrder itself here would double-wrap it
                    // (Order.platform_data.platform_data.shipping_address...)
                    // and silently break every raw-payload reader, including
                    // OrderAddressSummary.
                    'platform_data'  => $platformOrder['platform_data'] ?? $platformOrder,
                    'synced_at'      => now(),
                ]);
            }

            return $existing;
        }

        // New order — always lands awaiting confirmation. It must NOT jump to
        // fulfillment/completed on the way in; only the confirmation step (dashboard
        // or a customer WhatsApp reply) may advance it.
        $order = Order::create(array_merge([
            'store_id'               => $connection->store_id,
            'organization_id'        => $connection->store?->organization_id,
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
            'platform_data'          => $platformOrder['platform_data'] ?? $platformOrder,
            'synced_at'              => now(),
            'imported_at'            => now(),
        ], OrderSourceSummary::forConnection($connection)));

        if (config('inventory.reserve_online_pending_orders') === true) {
            $this->softReservePending($order);
        }

        OrderCreated::dispatch($order);

        if (filled($order->customer_phone)) {
            SendWhatsAppConfirmation::dispatch($order)->delay(now()->addSeconds(5));
        }

        return $order;
    }

    /**
     * Optional (config('inventory.reserve_online_pending_orders'), default
     * false) soft reservation for a brand-new pending order — reduces
     * available stock the moment it's imported, before anyone confirms it.
     *
     * This is deliberately just WarehouseAllocationService::allocate() — the
     * same call the Pending -> Confirmed transition makes. Allocating is
     * already idempotent per order (it returns the existing allocation if
     * one exists), so confirming later never reserves a second time; it just
     * finds this one already in place. Organization-less stores and any
     * allocation failure (e.g. an unmapped line, no warehouse configured yet)
     * are logged and skipped rather than blocking the import — the order
     * still exists, and confirmation will surface the same problem for real.
     */
    private function softReservePending(Order $order): void
    {
        $order->loadMissing('store.organization');

        if ($order->store?->organization === null) {
            return;
        }

        try {
            $this->allocations->allocate($order, $order->shippingCity);
        } catch (ValidationException $e) {
            Log::warning('OrderSyncService: soft reservation for pending order failed', [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'error'    => $e->getMessage(),
            ]);
        }
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
