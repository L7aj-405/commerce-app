<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Connectors\WooCommerceConnector;
use App\Http\Controllers\Controller;
use App\Jobs\OrderSyncJob;
use App\Jobs\ProductSyncJob;
use App\Models\Order;
use App\Models\OrderSyncBatch;
use App\Models\OrderSyncResult;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductSyncBatch;
use App\Models\ProductSyncResult;
use App\Models\ProductVariantChannelListing;
use App\Models\Store;
use App\Services\Catalog\ProductCleanupService;
use App\Services\Shopify\ShopifyCapabilityDiagnosticsService;
use App\Services\Shopify\ShopifyWebhookRegistrationService;
use App\Services\Sync\ProductSyncService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * A per-connection "control panel" — the single place to check auth status,
 * run/queue a sync, reset sync bookkeeping, or archive a bad test import,
 * without ever touching credentials by accident. Reset actions are
 * deliberately kept far (both in code and in the UI) from disconnect():
 * reset never reads/writes api_url/consumer_key/consumer_secret/
 * access_token/webhook_secret, disconnect never touches
 * ProductChannelListing/ProductVariantChannelListing/orders.
 */
class ConnectionProfileController extends Controller
{
    /** Verifies the connection belongs to the acting user's active store and returns it. Every action starts here. */
    private function requireConnection(Request $request, PlatformConnection $connection): Store
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $connection->store_id !== $store->id, 403);

        return $store;
    }

    public function show(Request $request, PlatformConnection $connection): Response
    {
        $store = $this->requireConnection($request, $connection);
        $connection->loadMissing('store.organization');

        $productMappingsCount = ProductChannelListing::query()->where('platform_connection_id', $connection->id)->count();
        $variantMappingsCount = ProductVariantChannelListing::query()->where('platform_connection_id', $connection->id)->count();
        $importedOrdersCount = Order::query()->where('platform_connection_id', $connection->id)->count();

        $latestResult = ProductSyncResult::query()
            ->where('platform_connection_id', $connection->id)
            ->with('batch')
            ->latest('created_at')
            ->first();

        $latestOrderResult = OrderSyncResult::query()
            ->where('platform_connection_id', $connection->id)
            ->with('batch')
            ->latest('created_at')
            ->first();

        $metadata = $connection->metadata ?? [];
        $productSync = $metadata['product_sync'] ?? null;
        $orderSync = $metadata['order_sync'] ?? null;

        return Inertia::render('Dashboard/Integrations/ConnectionProfile', [
            'connection' => [
                'id' => $connection->id,
                'platform' => $connection->platform,
                'connection_method' => $connection->connection_method,
                'label' => $connection->label,
                'status' => $connection->status,
                'shop_domain' => $connection->shop_domain,
                'api_url' => $connection->api_url,
                'is_syncing' => (bool) $connection->is_syncing,
                'created_at' => $connection->created_at,
            ],
            'store' => ['id' => $store->id, 'name' => $store->name],
            'auth' => $this->authStatus($connection),
            'webhooks' => $connection->platform === PlatformConnection::PLATFORM_SHOPIFY
                ? $this->shopifyWebhookStatus($connection)
                : null,
            'syncStatus' => [
                'last_product_sync_at' => $productSync['last_synced_at'] ?? null,
                'last_order_sync_at' => $orderSync['last_synced_at'] ?? null,
                // Distinct from the manual cursor above: this is the shared
                // `last_synced_at` column ONLY the every-minute scheduler
                // (routes/console.php) writes, i.e. truly automatic import
                // with no page ever opened.
                'last_automatic_sync_at' => $connection->last_synced_at?->toIso8601String(),
                'last_manual_sync_at' => $orderSync['last_synced_at'] ?? null,
                'product_mappings_count' => $productMappingsCount,
                'variant_mappings_count' => $variantMappingsCount,
                'imported_orders_count' => $importedOrdersCount,
                'last_sync_status' => $connection->last_sync_error !== null ? 'error' : ($connection->last_synced_at !== null ? 'ok' : 'never'),
                'last_sync_error' => $connection->last_sync_error ?? $productSync['last_error'] ?? $orderSync['last_error'] ?? null,
                'current_batch' => $latestResult?->batch !== null ? [
                    'batch_id' => $latestResult->batch->id,
                    'status' => $latestResult->batch->status,
                    'total_count' => $latestResult->batch->total_count,
                    'succeeded_count' => $latestResult->batch->succeeded_count,
                    'failed_count' => $latestResult->batch->failed_count,
                    'skipped_count' => $latestResult->batch->skipped_count,
                ] : null,
                'current_order_batch' => $latestOrderResult?->batch !== null ? [
                    'batch_id' => $latestOrderResult->batch->id,
                    'status' => $latestOrderResult->batch->status,
                    'total_count' => $latestOrderResult->batch->total_count,
                    'imported_count' => $latestOrderResult->batch->imported_count,
                    'updated_count' => $latestOrderResult->batch->updated_count,
                    'skipped_count' => $latestOrderResult->batch->skipped_count,
                    'failed_count' => $latestOrderResult->batch->failed_count,
                    'last_error' => $latestOrderResult->batch->last_error,
                    'started_at' => $latestOrderResult->batch->started_at?->toIso8601String(),
                    'completed_at' => $latestOrderResult->batch->completed_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    /**
     * Webhook status for the connection profile: per-topic active/failed/
     * missing/unknown, when the last webhook was actually received (never
     * assumed), the last import result, and — for the credential-less
     * webhook-only connection method — whether the required scopes/secret
     * are even usable. Never silently reports "working" without evidence.
     *
     * @return array<string, mixed>
     */
    private function shopifyWebhookStatus(PlatformConnection $connection): array
    {
        $stored = $connection->metadata['webhooks'] ?? null;
        $lastLog = $connection->syncLogs()
            ->where('type', 'like', 'webhook:%')
            ->latest('started_at')
            ->first();

        $eligible = $connection->effectiveWebhookSecret() !== null;

        return [
            'eligible' => $eligible,
            'ineligible_reason' => $eligible ? null : 'No webhook signing secret is configured for this connection yet.',
            'topics' => is_array($stored['topics'] ?? null)
                ? $stored['topics']
                : array_fill_keys(ShopifyWebhookRegistrationService::TOPICS, 'unknown'),
            'checked_at' => $stored['checked_at'] ?? null,
            'registration_error' => $stored['error'] ?? null,
            'last_webhook_at' => $connection->last_webhook_at?->toIso8601String(),
            'last_webhook_status' => $connection->webhook_status,
            'last_webhook_import_result' => $lastLog?->status,
            'last_webhook_import_error' => $lastLog?->error_message,
        ];
    }

    /**
     * Reuses IntegrationsController's existing platform-specific test logic
     * (Shopify admin-token/client-credentials/webhook, WooCommerce
     * system_status ping) — never re-implements it — then records a
     * unified, platform-agnostic auth_check on this connection's metadata so
     * the profile page can show ONE status even for platforms (WooCommerce,
     * YouCan) that have no dedicated status column of their own.
     *
     * IntegrationsController::testConnection() only ever branches on Shopify
     * — a WooCommerce connection silently falls through to "is status
     * active?" without ever calling the platform at all. This page's own
     * "Test connection" is explicitly meant to verify credentials, so
     * WooCommerce is tested here instead, via the connector's OWN existing
     * authenticate() (GET /system_status — the same safe, read-only,
     * already-used-elsewhere endpoint) rather than inventing a new one.
     *
     * A Shopify admin_client_credentials connection is tested via
     * ShopifyCapabilityDiagnosticsService — real per-endpoint checks
     * (shop/products/orders/locations), never the old
     * ShopifyAuthService::testConnection() hard gate on the token's
     * self-reported `scope` string (that string not literally containing
     * "read_products" doesn't mean the products endpoint actually fails —
     * it was, exactly the bug this fixes).
     */
    public function test(Request $request, PlatformConnection $connection, IntegrationsController $integrations, ShopifyCapabilityDiagnosticsService $diagnostics): JsonResponse
    {
        $this->requireConnection($request, $connection);

        $result = match (true) {
            $connection->platform === PlatformConnection::PLATFORM_WOOCOMMERCE => $this->testWooCommerce($connection),
            $connection->platform === PlatformConnection::PLATFORM_SHOPIFY
                && $connection->connection_method === PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS
                => $this->testShopifyCapabilities($connection, $diagnostics),
            default => $integrations->testConnection($request, $connection->platform)->getData(true),
        };

        $connection->update([
            'metadata' => array_merge($connection->metadata ?? [], [
                'auth_check' => [
                    'ok' => (bool) ($result['ok'] ?? false),
                    'message' => $result['message'] ?? null,
                    'checked_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        // A verified Shopify connection is exactly the moment automatic
        // order import can start working with zero further clicks — so a
        // successful "Test connection" also (best-effort, never blocking
        // or failing this response) registers the order webhooks. Never
        // for the dedicated CONNECTION_METHOD_WEBHOOK setup: that method
        // has no API credentials to register anything with, by design.
        if (($result['ok'] ?? false) === true) {
            $this->syncShopifyWebhooksIfApplicable($connection);
        }

        activity('platform_connection')
            ->performedOn($connection)
            ->causedBy($request->user())
            ->event('test_connection')
            ->withProperties(['ok' => $result['ok'] ?? false])
            ->log('Tested connection');

        return response()->json($result);
    }

    private function syncShopifyWebhooksIfApplicable(PlatformConnection $connection): void
    {
        if ($connection->platform !== PlatformConnection::PLATFORM_SHOPIFY) {
            return;
        }

        if (! in_array($connection->connection_method, [
            PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS,
            PlatformConnection::CONNECTION_METHOD_ADMIN_TOKEN,
        ], true)) {
            return;
        }

        try {
            app(ShopifyWebhookRegistrationService::class)->sync($connection);
        } catch (Throwable $e) {
            Log::warning('Shopify webhook auto-registration after test connection failed', [
                'connection' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Explicit repair/backfill action — (re-)registers Shopify's order
     * webhooks and refreshes their stored status, without requiring a full
     * "Test connection" run. A no-op (200, unchanged) for every other
     * platform/connection method.
     */
    public function syncWebhooks(Request $request, PlatformConnection $connection): JsonResponse
    {
        $this->requireConnection($request, $connection);

        if ($connection->platform !== PlatformConnection::PLATFORM_SHOPIFY
            || ! in_array($connection->connection_method, [
                PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS,
                PlatformConnection::CONNECTION_METHOD_ADMIN_TOKEN,
            ], true)
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'Webhook registration only applies to a Shopify connection using an Admin API token or client credentials.',
            ], 422);
        }

        $result = app(ShopifyWebhookRegistrationService::class)->sync($connection);

        activity('platform_connection')
            ->performedOn($connection)
            ->causedBy($request->user())
            ->event('sync_webhooks')
            ->withProperties($result)
            ->log('Synced Shopify webhooks');

        return response()->json(['ok' => $result['error'] === null] + $result);
    }

    /** @return array{ok:bool,message:string} */
    private function testWooCommerce(PlatformConnection $connection): array
    {
        try {
            $ok = (new WooCommerceConnector($connection))->authenticate();

            return ['ok' => $ok, 'message' => $ok ? 'Connected to WooCommerce.' : 'WooCommerce rejected the credentials.'];
        } catch (Throwable $e) {
            // Never include consumer_key/consumer_secret in the log — connection
            // id and exception message only, same convention as ShopifyAuthService.
            Log::warning('WooCommerce test connection failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Could not reach WooCommerce: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     *
     * ShopifyCapabilityDiagnosticsService::run() already persists its full
     * report (and clears a stale scope-related token_status/last_token_error
     * once products.read genuinely passes) — this only reduces that report
     * to the {ok, message} shape every other test() branch returns.
     * authStatus() below reads the full report back out for the rich
     * per-capability breakdown the UI shows.
     */
    private function testShopifyCapabilities(PlatformConnection $connection, ShopifyCapabilityDiagnosticsService $diagnostics): array
    {
        $report = $diagnostics->run($connection);
        $byKey = collect($report['capabilities'] ?? [])->keyBy('key');
        $productsPassed = ($byKey->get('products.read')['status'] ?? null) === 'passed';
        $shopPassed = ($byKey->get('shop.read')['status'] ?? null) === 'passed';

        if ($productsPassed || $shopPassed) {
            return ['ok' => true, 'message' => 'Connected to Shopify — product API is reachable.'];
        }

        return [
            'ok' => false,
            'message' => $byKey->get('shop.read')['message'] ?? $byKey->get('products.read')['message'] ?? 'Could not authenticate with Shopify.',
        ];
    }

    /** Sync products now — synchronous full pull via the existing ProductSyncService, unchanged. */
    public function syncProducts(Request $request, PlatformConnection $connection, ProductSyncService $sync): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        try {
            $result = $sync->syncFromPlatform($store, $connection->platform);

            // ProductSyncService itself never stamps the connection (only its
            // caller does — mirrors ProductController::syncFromPlatform
            // exactly) plus this page's own product-sync cursor metadata.
            $connection->update([
                'last_synced_at' => now(),
                'last_sync_error' => null,
                'synced_products_count' => (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0),
            ]);
            $this->mergeSyncMetadata($connection, 'product_sync', null);
            // A successful pull proves the token + products endpoint both
            // genuinely work — any stale "missing read_products" auth error
            // left over from a flawed diagnostic run must not survive this.
            $this->clearStaleScopeError($connection);

            return response()->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            Log::error('Connection profile: product sync failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
            $connection->update(['last_sync_error' => $e->getMessage()]);
            $this->mergeSyncMetadata($connection, 'product_sync', $e->getMessage());

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync orders now — QUEUED, never runs the platform API loop inside this
     * request (that inline loop is exactly what could hit PHP's
     * max_execution_time on a store with a large/slow order history).
     * Returns immediately with a batch_id the UI polls.
     *
     * Uses this connection's OWN order-sync cursor
     * (metadata.order_sync.last_synced_at), never the legacy shared
     * `last_synced_at` column the every-minute scheduler (routes/console.php)
     * reads — keeps the scheduler's incremental polling completely
     * unaffected by manual actions taken here. A connection with NO cursor
     * yet (first sync) defaults to the last N days
     * (config('sync.orders_initial_import_days')) rather than importing
     * unbounded history — "Full order resync" below is the explicit,
     * separate action for that.
     */
    public function syncOrders(Request $request, PlatformConnection $connection): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        $cursor = $connection->metadata['order_sync']['last_synced_at'] ?? null;
        $since = is_string($cursor)
            ? Carbon::parse($cursor)
            : now()->subDays((int) config('sync.orders_initial_import_days', 30));

        $batchId = $this->queueOrderSyncBatch($store, $request->user()->id, $connection, $since, fullResync: false);

        return response()->json([
            'batch_id' => $batchId,
            'status' => 'queued',
            'message' => 'Order sync queued.',
        ]);
    }

    /** Queue full product resync — reuses the exact ProductSyncBatch/ProductSyncResult/ProductSyncJob pipeline ProductSyncController::startSync uses, scoped to this one connection. */
    public function queueProductSync(Request $request, PlatformConnection $connection): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        $batch = ProductSyncBatch::create([
            'store_id' => $store->id,
            'organization_id' => $store->organization_id,
            'user_id' => $request->user()->id,
            'status' => ProductSyncBatch::STATUS_PENDING,
            'total_count' => 1,
            'payload' => ['connection_ids' => [$connection->id]],
        ]);

        $result = ProductSyncResult::create([
            'batch_id' => $batch->id,
            'store_id' => $store->id,
            'platform_connection_id' => $connection->id,
            'platform' => $connection->platform,
            'status' => ProductSyncResult::STATUS_QUEUED,
        ]);

        ProductSyncJob::dispatch($result->id);

        return response()->json(['status' => 'queued', 'batch_id' => $batch->id]);
    }

    /** Queue a full order resync — no lower bound at all (unlike "Sync orders now", which defaults an unconfigured connection to the last N days). Still updates existing orders in place, never duplicates (same idempotent OrderSyncService::saveOrder() the incremental path and every webhook use). */
    public function queueOrderSync(Request $request, PlatformConnection $connection): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        // A queued full resync starts clean — clear this connection's own
        // order cursor too, so the job (which passes no $since) and this
        // page's own bookkeeping agree.
        $this->clearSyncMetadata($connection, 'order_sync');

        $batchId = $this->queueOrderSyncBatch($store, $request->user()->id, $connection, since: null, fullResync: true);

        return response()->json([
            'batch_id' => $batchId,
            'status' => 'queued',
            'message' => 'Full order resync queued.',
        ]);
    }

    /** Shared by syncOrders()/queueOrderSync() — one OrderSyncBatch + one OrderSyncResult (single connection) + one queued OrderSyncJob. */
    private function queueOrderSyncBatch(Store $store, string $userId, PlatformConnection $connection, ?CarbonInterface $since, bool $fullResync): string
    {
        $batch = OrderSyncBatch::create([
            'store_id' => $store->id,
            'organization_id' => $store->organization_id,
            'user_id' => $userId,
            'status' => OrderSyncBatch::STATUS_QUEUED,
            'total_count' => 1,
            'started_at' => now(),
            'payload' => ['connection_id' => $connection->id, 'full_resync' => $fullResync],
        ]);

        $result = OrderSyncResult::create([
            'batch_id' => $batch->id,
            'store_id' => $store->id,
            'platform_connection_id' => $connection->id,
            'platform' => $connection->platform,
            'status' => OrderSyncResult::STATUS_QUEUED,
            'full_resync' => $fullResync,
        ]);

        OrderSyncJob::dispatch($result->id, $since?->toIso8601String());

        return $batch->id;
    }

    /** Poll the outcome of a queued order-sync batch — scoped to the acting user's active store. Mirrors ProductSyncController::getSyncBatchStatus. */
    public function getOrderSyncBatchStatus(Request $request, PlatformConnection $connection, string $batch): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        $model = OrderSyncBatch::query()
            ->where('store_id', $store->id)
            ->with('results.connection:id,platform,label')
            ->find($batch);

        abort_if($model === null, 404);

        return response()->json([
            'batch_id' => $model->id,
            'status' => $model->status,
            'total_count' => $model->total_count,
            'imported_count' => $model->imported_count,
            'updated_count' => $model->updated_count,
            'skipped_count' => $model->skipped_count,
            'failed_count' => $model->failed_count,
            'last_error' => $model->last_error,
            'started_at' => $model->started_at?->toIso8601String(),
            'completed_at' => $model->completed_at?->toIso8601String(),
            'results' => $model->results->map(fn (OrderSyncResult $r) => [
                'connection_id' => $r->platform_connection_id,
                'platform' => $r->platform,
                'label' => $r->connection?->label ?: ucfirst($r->platform),
                'status' => $r->status,
                'full_resync' => $r->full_resync,
                'imported' => $r->imported_count,
                'updated' => $r->updated_count,
                'skipped' => $r->skipped_count,
                'failed' => $r->failed_count,
                'last_error' => $r->last_error,
            ]),
        ]);
    }

    /**
     * Reset product mappings for THIS connection only — deletes
     * ProductChannelListing (cascades to ProductVariantChannelListing) for
     * platform_connection_id = this connection, never touches another
     * connection's mappings, never deletes the local product/variant/
     * inventory, never touches credentials. Reuses
     * ProductCleanupService::resetSyncForConnection (already used by the
     * Products Index "Reset sync" bulk action) so the two entry points can
     * never disagree about what "reset" means.
     */
    public function resetProductMappings(Request $request, PlatformConnection $connection, ProductCleanupService $cleanup): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereHas('channelListings', fn ($q) => $q->where('platform_connection_id', $connection->id))
            ->get();

        $results = DB::transaction(fn () => $cleanup->resetSyncForConnection($products, $connection));

        activity('platform_connection')
            ->performedOn($connection)
            ->causedBy($request->user())
            ->event('reset_product_mappings')
            ->withProperties(['product_count' => $products->count()])
            ->log('Reset product mappings');

        return response()->json([
            'results' => $results,
            'summary' => ['products_affected' => $products->count()],
        ]);
    }

    /** Clears only this connection's OWN product-sync bookkeeping (metadata.product_sync) — never touches mappings, credentials, or products. */
    public function resetProductCursor(Request $request, PlatformConnection $connection): JsonResponse
    {
        $this->requireConnection($request, $connection);

        $this->clearSyncMetadata($connection, 'product_sync');

        activity('platform_connection')->performedOn($connection)->causedBy($request->user())
            ->event('reset_product_cursor')->log('Reset product sync cursor');

        return response()->json(['ok' => true]);
    }

    /** Clears only this connection's OWN order-sync cursor (metadata.order_sync) — the legacy scheduler's `last_synced_at` column is untouched, so its incremental polling for every OTHER connection is unaffected. */
    public function resetOrderCursor(Request $request, PlatformConnection $connection): JsonResponse
    {
        $this->requireConnection($request, $connection);

        $this->clearSyncMetadata($connection, 'order_sync');

        activity('platform_connection')->performedOn($connection)->causedBy($request->user())
            ->event('reset_order_cursor')->log('Reset order sync cursor');

        return response()->json(['ok' => true]);
    }

    /**
     * Archive (never purge) every product imported from THIS connection.
     * Reuses ProductCleanupService::archive() — status flips to 'archived',
     * order/return history and inventory ledger are never touched.
     */
    public function archiveImportedProducts(Request $request, PlatformConnection $connection, ProductCleanupService $cleanup): JsonResponse
    {
        $store = $this->requireConnection($request, $connection);

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereHas('channelListings', fn ($q) => $q->where('platform_connection_id', $connection->id))
            ->get();

        $results = DB::transaction(fn () => $cleanup->archive($products));

        activity('platform_connection')
            ->performedOn($connection)
            ->causedBy($request->user())
            ->event('archive_imported_products')
            ->withProperties(['product_count' => $products->count()])
            ->log('Archived imported products');

        return response()->json([
            'results' => $results,
            'summary' => ['products_archived' => $products->count()],
        ]);
    }

    /**
     * The dangerous action. Requires typed confirmation (mirrors the product
     * purge "type PURGE" pattern) and — unlike every reset action above —
     * DOES wipe credentials, since that's the whole point of the distinction:
     * reset keeps you connected, disconnect does not. Mappings/products/
     * orders are still left untouched; disconnect is about the auth
     * relationship, not the imported data.
     */
    public function disconnect(Request $request, PlatformConnection $connection): JsonResponse
    {
        $this->requireConnection($request, $connection);

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'in:DISCONNECT'],
        ]);

        DB::transaction(function () use ($connection): void {
            $connection->update([
                'status' => 'disconnected',
                'is_syncing' => false,
                'api_url' => null,
                'consumer_key' => null,
                'consumer_secret' => null,
                'access_token' => null,
                'webhook_secret' => null,
                'webhook_status' => null,
            ]);
        });

        activity('platform_connection')
            ->performedOn($connection)
            ->causedBy($request->user())
            ->event('disconnect')
            ->log('Disconnected connection');

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{
     *     status:string,checked_at:?string,error:?string,warning:?string,
     *     capabilities:array{token:string,shop:string,products_read:string,orders_read:string,inventory_locations:string},
     *     capability_messages:array<string,string>,
     * }
     *
     * `status`/`error` stay the single "is this connection usable" verdict
     * the rest of the app (and older UI) expects. `capabilities` is the new,
     * separate per-endpoint breakdown (requirement: never let one endpoint's
     * failure — or a stale scope string — read as "the whole connection is
     * broken" when products/orders/etc. can be judged independently).
     */
    private function authStatus(PlatformConnection $connection): array
    {
        if ($connection->status === 'disconnected') {
            return $this->authResult('needs_setup', null, null, null, $this->emptyCapabilities());
        }

        if ($connection->platform === 'shopify' && $connection->connection_method === PlatformConnection::CONNECTION_METHOD_WEBHOOK) {
            $ok = $connection->isWebhookVerified();

            return $this->authResult(
                $ok ? 'connected' : 'needs_setup',
                $ok ? $connection->last_webhook_at?->toIso8601String() : null,
                null,
                null,
                array_merge($this->emptyCapabilities(), ['token' => $ok ? 'ok' : 'unknown', 'shop' => $ok ? 'ok' : 'skipped']),
            );
        }

        if ($connection->platform === 'shopify' && $connection->connection_method === PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS) {
            return $this->shopifyClientCredentialsAuthStatus($connection);
        }

        $check = $connection->metadata['auth_check'] ?? null;

        if (is_array($check)) {
            $ok = (bool) ($check['ok'] ?? false);

            return $this->authResult(
                $ok ? 'connected' : 'error',
                $check['checked_at'] ?? null,
                $ok ? null : ($check['message'] ?? null),
                null,
                array_merge($this->emptyCapabilities(), ['token' => $ok ? 'ok' : 'error', 'shop' => $ok ? 'ok' : 'error']),
            );
        }

        return $this->authResult('needs_setup', null, null, null, $this->emptyCapabilities());
    }

    /**
     * Reads back the report ShopifyCapabilityDiagnosticsService::run()
     * persisted (settings.diagnostics) — never re-runs the network calls
     * just to render the page. `status` is "connected" the moment EITHER
     * shop.read or products.read genuinely passed; a static scope string
     * disagreeing with a passing products.read only ever produces a
     * `warning`, never `error` (the bug this whole change fixes).
     */
    private function shopifyClientCredentialsAuthStatus(PlatformConnection $connection): array
    {
        $diagnostics = $connection->settings['diagnostics'] ?? null;

        if (! is_array($diagnostics)) {
            $tokenStatus = $connection->settings['token_status'] ?? null;

            return match ($tokenStatus) {
                'valid' => $this->authResult('connected', $connection->settings['last_token_generated_at'] ?? null, null, null, array_merge($this->emptyCapabilities(), ['token' => 'ok'])),
                'failed' => $this->authResult('error', null, $connection->settings['last_token_error'] ?? null, null, array_merge($this->emptyCapabilities(), ['token' => 'error'])),
                default => $this->authResult('needs_setup', null, null, null, $this->emptyCapabilities()),
            };
        }

        $byKey = collect($diagnostics['capabilities'] ?? [])->keyBy('key');
        $capStatus = fn (?string $key) => match ($byKey->get($key)['status'] ?? null) {
            'passed' => 'ok',
            'failed' => 'error',
            default => 'skipped',
        };

        $tokenOk = (bool) ($diagnostics['token']['generated'] ?? false);
        $productsPassed = ($byKey->get('products.read')['status'] ?? null) === 'passed';
        $shopPassed = ($byKey->get('shop.read')['status'] ?? null) === 'passed';
        $status = ($productsPassed || $shopPassed) ? 'connected' : 'error';

        $error = $status === 'error'
            ? ($byKey->get('shop.read')['message'] ?? $byKey->get('products.read')['message'] ?? 'Could not authenticate with Shopify.')
            : null;

        $reportedScopes = $diagnostics['token']['reported_scopes'] ?? [];
        $warning = ($productsPassed && ! in_array('read_products', $reportedScopes, true))
            ? 'Scope introspection did not confirm read_products, but product API read succeeded.'
            : null;

        $messages = [];
        foreach (['shop.read' => 'shop', 'products.read' => 'products_read', 'orders.read' => 'orders_read', 'locations.read' => 'inventory_locations'] as $reportKey => $field) {
            if (($byKey->get($reportKey)['status'] ?? null) === 'failed') {
                $messages[$field] = $byKey->get($reportKey)['message'] ?? null;
            }
        }

        return $this->authResult($status, $diagnostics['last_checked_at'] ?? null, $error, $warning, [
            'token' => $tokenOk ? 'ok' : 'error',
            'shop' => $capStatus('shop.read'),
            'products_read' => $capStatus('products.read'),
            'orders_read' => $capStatus('orders.read'),
            'inventory_locations' => $capStatus('locations.read'),
        ], $messages);
    }

    /** @param array{token:string,shop:string,products_read:string,orders_read:string,inventory_locations:string} $capabilities @param array<string,string> $messages */
    private function authResult(string $status, ?string $checkedAt, ?string $error, ?string $warning, array $capabilities, array $messages = []): array
    {
        return [
            'status' => $status,
            'checked_at' => $checkedAt,
            'error' => $error,
            'warning' => $warning,
            'capabilities' => $capabilities,
            'capability_messages' => $messages,
        ];
    }

    /** @return array{token:string,shop:string,products_read:string,orders_read:string,inventory_locations:string} */
    private function emptyCapabilities(): array
    {
        return ['token' => 'unknown', 'shop' => 'skipped', 'products_read' => 'skipped', 'orders_read' => 'skipped', 'inventory_locations' => 'skipped'];
    }

    /**
     * Clears settings.token_status/last_token_error ONLY when the stale
     * error looks scope-related — a genuinely different auth failure (bad
     * credentials, network) must not be silently wiped just because a sync
     * happened to succeed with cached/still-valid state.
     */
    private function clearStaleScopeError(PlatformConnection $connection): void
    {
        $settings = $connection->settings ?? [];
        $lastError = $settings['last_token_error'] ?? null;

        if (($settings['token_status'] ?? null) !== 'failed' || ! is_string($lastError)) {
            return;
        }

        if (! str_contains(strtolower($lastError), 'scope')) {
            return;
        }

        $settings['token_status'] = 'valid';
        $settings['last_token_error'] = null;
        $connection->update(['settings' => $settings]);
    }

    private function mergeSyncMetadata(PlatformConnection $connection, string $key, ?string $error): void
    {
        $connection->update([
            'metadata' => array_merge($connection->metadata ?? [], [
                $key => ['last_synced_at' => now()->toIso8601String(), 'last_error' => $error],
            ]),
        ]);
    }

    private function clearSyncMetadata(PlatformConnection $connection, string $key): void
    {
        $metadata = $connection->metadata ?? [];
        unset($metadata[$key]);
        $connection->update(['metadata' => $metadata]);
    }
}
