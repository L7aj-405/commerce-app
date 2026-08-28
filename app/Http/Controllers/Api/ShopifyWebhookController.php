<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Jobs\ShopifyOrderWebhookJob;
use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Services\Shopify\ShopifyProductMapper;
use App\Services\Shopify\ShopifyWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyWebhookController
{
    private const PRODUCT_TOPICS = ['products/create', 'products/update'];
    private const ORDER_TOPICS   = ['orders/create', 'orders/updated'];

    // orders/cancelled is accepted (never a 404/ignored-topic reject) but
    // deliberately NOT routed through the order mapper — cancelling/
    // reverting a local order is a confirmation/fulfillment-workflow
    // decision, out of scope for this webhook endpoint. Logged only, so the
    // event is visible without silently changing local order state.
    private const CANCEL_TOPICS  = ['orders/cancelled'];

    public function __construct(
        private readonly ShopifyWebhookVerifier $verifier,
        private readonly ShopifyProductMapper $products,
    ) {}

    /**
     * Single endpoint for every Shopify webhook topic — the event is read
     * from X-Shopify-Topic, not the URL. Guest route (see routes/api.php),
     * no tenant context, so the connection is resolved manually and every
     * model call that touches it must run withoutTenancy().
     *
     * Eligibility no longer requires connection_method === 'webhook' — that
     * check meant a normal admin_client_credentials/admin_token Shopify
     * connection (the one "Sync" actually uses) could never receive
     * webhooks at all, which was the root cause of Shopify orders never
     * importing automatically. See PlatformConnection::effectiveWebhookSecret().
     */
    public function handle(Request $request, string $connection): JsonResponse
    {
        $conn = PlatformConnection::withoutTenancy(
            fn () => PlatformConnection::query()->find($connection)
        );

        if ($conn === null) {
            Log::info('Shopify webhook received for unknown connection', ['connection' => $connection]);

            return response()->json(['error' => 'Unknown connection'], 404);
        }

        $topic     = (string) $request->header('X-Shopify-Topic', '');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        Log::info('Shopify webhook received', [
            'connection' => $conn->id,
            'topic'      => $topic,
            'shop_domain' => $shopDomain,
        ]);

        $effectiveSecret = $conn->effectiveWebhookSecret();

      if ($conn->platform !== PlatformConnection::PLATFORM_SHOPIFY
    || $conn->status === 'disabled'
    || $effectiveSecret === null
) {
            Log::warning('Shopify webhook rejected: connection not eligible', [
                'connection' => $conn->id,
                'topic'      => $topic,
                'reason'     => $effectiveSecret === null ? 'no_webhook_secret' : 'ineligible_connection',
            ]);

            return response()->json(['error' => 'Connection not eligible for webhooks'], 403);
        }

        $expectedShopDomain = $this->normalizeShopDomain((string) $conn->shop_domain);
$receivedShopDomain = $this->normalizeShopDomain((string) $shopDomain);

if ($receivedShopDomain === '' || $expectedShopDomain === '' || ! hash_equals($expectedShopDomain, $receivedShopDomain)) {
            Log::warning('Shopify webhook rejected: shop domain mismatch', ['connection' => $conn->id, 'topic' => $topic]);

            return response()->json(['error' => 'Shop domain mismatch'], 403);
        }

        $rawBody = $request->getContent();
        $hmac    = $request->header('X-Shopify-Hmac-Sha256');

        if (! $this->verifier->verify($rawBody, $hmac, $effectiveSecret)) {
            Log::warning('Shopify webhook rejected: invalid HMAC signature', ['connection' => $conn->id, 'topic' => $topic]);

            PlatformConnection::withoutTenancy(fn () => $conn->update(['webhook_status' => PlatformConnection::WEBHOOK_STATUS_FAILED]));

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');

        if ($webhookId !== '') {
            // 'verified' is included (not just 'processed') because order
            // topics now finish asynchronously — a duplicate delivery must
            // be caught even while the first job is still queued/running.
            $duplicate = SyncLog::withoutTenancy(fn () => SyncLog::query()
                ->where('platform_connection_id', $conn->id)
                ->where('external_id', $webhookId)
                ->whereIn('status', ['verified', 'processed'])
                ->exists());

            if ($duplicate) {
                Log::info('Shopify webhook ignored: duplicate delivery', ['connection' => $conn->id, 'topic' => $topic, 'webhook_id' => $webhookId]);
                $this->log($conn, $topic, $webhookId, 'ignored_duplicate');

                return response()->json(['status' => 'ignored_duplicate']);
            }
        }

        $log = $this->log($conn, $topic, $webhookId, 'verified');

        // Verified — promote the connection immediately, regardless of
        // whether the import itself (below) succeeds. "Last webhook
        // received" must reflect Shopify actually reaching us, not whether
        // the mapping happened to succeed.
        PlatformConnection::withoutTenancy(fn () => $conn->update([
            'webhook_status'  => PlatformConnection::WEBHOOK_STATUS_VERIFIED,
            'last_webhook_at' => now(),
            'status'          => 'active',
        ]));

        if (in_array($topic, self::ORDER_TOPICS, true)) {
            // Queued so Shopify always gets an immediate 200 — Shopify
            // retries aggressively (and can eventually disable the
            // webhook) on a slow or non-2xx response. The job reuses the
            // exact same idempotent OrderSyncService::saveOrder() manual/
            // scheduled sync and the WooCommerce webhook use.
            ShopifyOrderWebhookJob::dispatch($conn->id, $topic, json_decode($rawBody, true) ?? [], $log->id);
            Log::info('Shopify order webhook job dispatched', ['connection' => $conn->id, 'topic' => $topic]);

            return response()->json(['status' => 'queued']);
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

            if (in_array($topic, self::PRODUCT_TOPICS, true)) {
                $this->products->map($payload, $conn);
            } elseif (in_array($topic, self::CANCEL_TOPICS, true)) {
                SyncLog::withoutTenancy(fn () => $log->update(['status' => 'ignored', 'completed_at' => now()]));

                return response()->json(['status' => 'ignored_cancel_topic']);
            } else {
                SyncLog::withoutTenancy(fn () => $log->update(['status' => 'ignored', 'completed_at' => now()]));

                return response()->json(['status' => 'ignored_topic']);
            }

            SyncLog::withoutTenancy(fn () => $log->update(['status' => 'processed', 'completed_at' => now()]));

            return response()->json(['status' => 'ok']);
        } catch (Throwable $e) {
            Log::error('Shopify webhook processing failed', [
                'connection' => $conn->id,
                'topic'      => $topic,
                'error'      => $e->getMessage(),
            ]);

            SyncLog::withoutTenancy(fn () => $log->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
            ]));

            // Still 200 — a bad local mapping shouldn't make Shopify hammer
            // retries; the failure is fully logged for follow-up.
            return response()->json(['status' => 'accepted_but_failed']);
        }
    }

    private function normalizeShopDomain(string $domain): string
{
    $domain = trim(strtolower($domain));
    $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
    $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;

    return rtrim($domain, '/');
}

    private function log(PlatformConnection $conn, string $topic, string $webhookId, string $status): SyncLog
    {
        $action = in_array($topic, self::PRODUCT_TOPICS, true)
            ? 'product'
            : ((in_array($topic, self::ORDER_TOPICS, true) || in_array($topic, self::CANCEL_TOPICS, true)) ? 'order' : null);

        return SyncLog::withoutTenancy(fn () => SyncLog::create([
            'store_id'                => $conn->store_id,
            'platform_connection_id'  => $conn->id,
            'platform'                => $conn->platform,
            'direction'               => 'pull',
            'action'                  => $action,
            'external_id'             => $webhookId !== '' ? $webhookId : null,
            'type'                    => 'webhook:' . $topic,
            'status'                  => $status,
            'started_at'              => now(),
        ]));
    }
}
