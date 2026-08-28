<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Services\Shopify\ShopifyOrderMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one verified Shopify orders/create|updated webhook off the
 * request thread, so ShopifyWebhookController can return 200 to Shopify
 * immediately — Shopify retries aggressively (and eventually disables the
 * webhook) if a response is slow or non-2xx. Reuses ShopifyOrderMapper,
 * which itself reuses OrderSyncService::saveOrder() — the exact same
 * idempotent upsert manual/scheduled sync and the WooCommerce webhook all
 * use, so this can never create a duplicate order.
 *
 * Shopify-only. WooCommerceWebhookController stays fully synchronous,
 * completely unchanged — this job has no WooCommerce equivalent and none
 * is needed, since WooCommerce's automatic import was already confirmed
 * working before this change.
 */
class ShopifyOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $connectionId,
        public readonly string $topic,
        public readonly array $payload,
        public readonly ?string $syncLogId,
    ) {}

    public function handle(ShopifyOrderMapper $mapper): void
    {
        $connection = PlatformConnection::withoutTenancy(
            fn () => PlatformConnection::query()->find($this->connectionId)
        );

        if ($connection === null) {
            Log::warning('Shopify webhook job: connection no longer exists', [
                'connection' => $this->connectionId,
                'topic' => $this->topic,
            ]);

            return;
        }

        Log::info('Shopify webhook job started', [
            'connection' => $connection->id,
            'shop_domain' => $connection->shop_domain,
            'topic' => $this->topic,
        ]);

        $log = $this->syncLogId !== null
            ? SyncLog::withoutTenancy(fn () => SyncLog::query()->find($this->syncLogId))
            : null;

        try {
            $order = $mapper->map($this->payload, $connection);

            Log::info('Shopify webhook job: local order created/updated', [
                'connection' => $connection->id,
                'topic' => $this->topic,
                'order_id' => $order?->id,
                'created' => $order?->wasRecentlyCreated ?? false,
            ]);

            SyncLog::withoutTenancy(fn () => $log?->update(['status' => 'processed', 'completed_at' => now()]));
        } catch (Throwable $e) {
            Log::error('Shopify webhook job: order import failed', [
                'connection' => $connection->id,
                'topic' => $this->topic,
                'error' => $e->getMessage(),
            ]);

            SyncLog::withoutTenancy(fn () => $log?->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]));
        }
    }
}
