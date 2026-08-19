<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryAdjustment;
use App\Models\PlatformConnection;
use App\Models\ProductChannelListing;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncInventoryToWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;
    public int $timeout = 60;

    private const RETRY_DELAY_SECONDS = 300;

    public function __construct(
        public Store $store,
        public InventoryAdjustment $adjustment,
    ) {}

    /**
     * Exponential backoff between attempts (handled by the queue worker between auto-retries):
     *   30s, 60s, 2m, 5m, 10m
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        $connections = PlatformConnection::query()
            ->where('store_id', $this->store->id)
            ->whereIn('platform', [
                PlatformConnection::PLATFORM_WOOCOMMERCE,
                PlatformConnection::PLATFORM_SHOPIFY,
                PlatformConnection::PLATFORM_YOUCAN,
            ])
            ->where('status', 'active')
            ->get();

        if ($connections->isEmpty()) {
            $this->markSynced(['note' => 'No active platform connections for store.']);
            return;
        }

        $results = [];
        $anyFailed = false;

        foreach ($connections as $connection) {
            $platform = $connection->platform;

            $platformProductId = $this->resolvePlatformProductId($connection, $this->adjustment->product_id);

            if ($platformProductId === null) {
                $results[$platform] = [
                    'ok'    => false,
                    'error' => 'No external_id found for product on this platform.',
                ];
                continue;
            }

            $payload = [
                'product_id'      => $platformProductId,
                'quantity_change' => (int) $this->adjustment->quantity_change,
                'new_stock'       => $this->sellableStock((string) $this->adjustment->product_id),
                'source'          => $this->adjustment->source ?? 'pos_sale',
            ];

            try {
                $response = match ($platform) {
                    PlatformConnection::PLATFORM_WOOCOMMERCE => $this->syncToWooCommerce($connection, $payload),
                    PlatformConnection::PLATFORM_SHOPIFY     => $this->syncToShopify($connection, $payload),
                    PlatformConnection::PLATFORM_YOUCAN      => $this->syncToYouCan($connection, $payload),
                    default => throw new RuntimeException("Unsupported platform: {$platform}"),
                };

                $results[$platform] = [
                    'ok'       => true,
                    'status'   => $response['_status'] ?? null,
                    'response' => $response,
                ];

                Log::info('Inventory sync succeeded', [
                    'adjustment_id' => $this->adjustment->id,
                    'platform'      => $platform,
                    'platform_pid'  => $platformProductId,
                    'change'        => $payload['quantity_change'],
                ]);
            } catch (Throwable $e) {
                $anyFailed = true;
                $results[$platform] = [
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ];

                Log::warning('Inventory sync failed for platform', [
                    'adjustment_id' => $this->adjustment->id,
                    'platform'      => $platform,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        if ($anyFailed) {
            $this->markFailed($results);
            $this->release(self::RETRY_DELAY_SECONDS);
            return;
        }

        $this->markSynced($results);
    }

    /**
     * Units the storefront may actually sell: the sum across this store's
     * sellable warehouses, excluding the damaged location returned goods are
     * written off to. Reading `adjustment->quantity_after` instead would push a
     * single warehouse's balance — and, once a damaged restock produced an
     * adjustment, would advertise written-off units as available.
     */
    private function sellableStock(string $productId): int
    {
        return (int) Stock::query()
            ->where('product_id', $productId)
            ->whereHas('warehouse', fn ($w) => $w->sellable())
            ->sum('quantity');
    }

    public function failed(Throwable $e): void
    {
        Log::error('SyncInventoryToWebhooks job failed permanently', [
            'adjustment_id' => $this->adjustment->id,
            'store_id'      => $this->store->id,
            'error'         => $e->getMessage(),
        ]);

        $this->markFailed(['_terminal_error' => $e->getMessage()]);
    }

    /**
     * PUT /wp-json/wc/v3/products/{product_id}
     * Body: { stock_quantity: new_stock }
     *
     * @param  array{product_id: mixed, new_stock: int}  $payload
     * @return array<string, mixed>
     */
    private function syncToWooCommerce(PlatformConnection $connection, array $payload): array
    {
        $baseUrl = rtrim((string) $connection->api_url, '/') . '/wp-json/wc/v3';
        $url     = $baseUrl . '/products/' . rawurlencode((string) $payload['product_id']);

        $response = Http::withBasicAuth(
                (string) $connection->consumer_key,
                (string) $connection->consumer_secret,
            )
            ->acceptJson()
            ->timeout(30)
            ->put($url, [
                'stock_quantity' => $payload['new_stock'],
            ]);

        return $this->envelope($response);
    }

    /**
     * POST /admin/api/2024-01/inventory_levels/adjust.json
     * Body: { available_adjustment: quantity_change }
     *
     * Note: Shopify's real adjust endpoint also requires `inventory_item_id` and
     * `location_id`. We pass what the spec requested plus the resolved inventory_item_id
     * (the external_id we looked up). If your store needs a specific location_id,
     * stash it on PlatformConnection.metadata.location_id.
     *
     * @param  array{product_id: mixed, quantity_change: int}  $payload
     * @return array<string, mixed>
     */
    private function syncToShopify(PlatformConnection $connection, array $payload): array
    {
        $domain = (string) $connection->shop_domain;
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $url    = "https://{$domain}/admin/api/2024-01/inventory_levels/adjust.json";

        $body = [
            'available_adjustment' => $payload['quantity_change'],
            'inventory_item_id'    => $payload['product_id'],
        ];

        $locationId = $connection->metadata['location_id'] ?? null;
        if ($locationId !== null) {
            $body['location_id'] = $locationId;
        }

        $response = Http::withHeaders([
                'X-Shopify-Access-Token' => (string) $connection->access_token,
            ])
            ->acceptJson()
            ->timeout(30)
            ->post($url, $body);

        return $this->envelope($response);
    }

    /**
     * PUT /api/v1/products/{product_id}
     * Body: { stock: new_stock }
     *
     * @param  array{product_id: mixed, new_stock: int}  $payload
     * @return array<string, mixed>
     */
    private function syncToYouCan(PlatformConnection $connection, array $payload): array
    {
        $baseUrl = rtrim((string) $connection->api_url, '/') ?: 'https://api.youcan.shop';
        $url     = $baseUrl . '/api/v1/products/' . rawurlencode((string) $payload['product_id']);

        $response = Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(30)
            ->put($url, [
                'stock' => $payload['new_stock'],
            ]);

        return $this->envelope($response);
    }

    /**
     * Common response shape so failed() / sync_metadata always have the same fields.
     */
    private function envelope(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'HTTP %d: %s',
                $response->status(),
                substr($response->body(), 0, 500),
            ));
        }

        $json = $response->json();

        return [
            '_status'  => $response->status(),
            '_body'    => is_array($json) ? $json : ['raw' => $response->body()],
        ];
    }

    /** Resolve the platform-specific product id from the channel listing. */
    private function resolvePlatformProductId(PlatformConnection $connection, string $localProductId): ?string
    {
        $external = ProductChannelListing::query()
            ->where('product_id', $localProductId)
            ->where('platform_connection_id', $connection->id)
            ->value('external_product_id');

        if (is_string($external) && $external !== '') {
            return $external;
        }

        // Migration fallback only for rows that pre-date channel listings.
        $product = $this->adjustment->product;

        if ($product !== null && $product->platform === $connection->platform && ! empty($product->external_id)) {
            return (string) $product->external_id;
        }

        return null;
    }

    private function markSynced(array $metadata): void
    {
        $this->adjustment->refresh()->update([
            'sync_status'   => 'synced',
            'synced_at'     => now(),
            'sync_metadata' => array_merge(
                $this->adjustment->sync_metadata ?? [],
                ['last_run' => now()->toIso8601String(), 'results' => $metadata],
            ),
        ]);
    }

    private function markFailed(array $metadata): void
    {
        $this->adjustment->refresh()->update([
            'sync_status'   => 'failed',
            'sync_metadata' => array_merge(
                $this->adjustment->sync_metadata ?? [],
                ['last_run' => now()->toIso8601String(), 'results' => $metadata],
            ),
        ]);
    }
}
