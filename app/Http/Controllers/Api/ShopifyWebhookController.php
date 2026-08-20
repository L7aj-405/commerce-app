<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Services\Shopify\ShopifyOrderMapper;
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

    public function __construct(
        private readonly ShopifyWebhookVerifier $verifier,
        private readonly ShopifyProductMapper $products,
        private readonly ShopifyOrderMapper $orders,
    ) {}

    /**
     * Single endpoint for every Shopify webhook topic — the event is read
     * from X-Shopify-Topic, not the URL. Guest route (see routes/api.php),
     * no tenant context, so the connection is resolved manually and every
     * model call that touches it must run withoutTenancy().
     */
    public function handle(Request $request, string $connection): JsonResponse
    {
        $conn = PlatformConnection::withoutTenancy(
            fn () => PlatformConnection::query()->find($connection)
        );

        if ($conn === null) {
            return response()->json(['error' => 'Unknown connection'], 404);
        }

        if ($conn->platform !== PlatformConnection::PLATFORM_SHOPIFY
            || ! $conn->isWebhookMethod()
            || $conn->status === 'disabled'
        ) {
            return response()->json(['error' => 'Connection not eligible for webhooks'], 403);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if ($shopDomain === null || $conn->shop_domain === null || ! hash_equals($conn->shop_domain, $shopDomain)) {
            return response()->json(['error' => 'Shop domain mismatch'], 403);
        }

        $rawBody = $request->getContent();
        $hmac    = $request->header('X-Shopify-Hmac-Sha256');

        if (! $this->verifier->verify($rawBody, $hmac, $conn->webhook_secret)) {
            PlatformConnection::withoutTenancy(fn () => $conn->update(['webhook_status' => PlatformConnection::WEBHOOK_STATUS_FAILED]));

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $topic     = (string) $request->header('X-Shopify-Topic', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');

        if ($webhookId !== '') {
            $duplicate = SyncLog::withoutTenancy(fn () => SyncLog::query()
                ->where('platform_connection_id', $conn->id)
                ->where('external_id', $webhookId)
                ->where('status', 'processed')
                ->exists());

            if ($duplicate) {
                $this->log($conn, $topic, $webhookId, 'ignored_duplicate');

                return response()->json(['status' => 'ignored_duplicate']);
            }
        }

        $log = $this->log($conn, $topic, $webhookId, 'verified');

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

            if (in_array($topic, self::PRODUCT_TOPICS, true)) {
                $this->products->map($payload, $conn);
            } elseif (in_array($topic, self::ORDER_TOPICS, true)) {
                $this->orders->map($payload, $conn);
            } else {
                SyncLog::withoutTenancy(fn () => $log->update(['status' => 'ignored', 'completed_at' => now()]));

                return response()->json(['status' => 'ignored_topic']);
            }

            SyncLog::withoutTenancy(fn () => $log->update(['status' => 'processed', 'completed_at' => now()]));

            // First successfully-verified webhook is what promotes the
            // connection out of "pending" — never marked active on save alone.
            PlatformConnection::withoutTenancy(fn () => $conn->update([
                'webhook_status'  => PlatformConnection::WEBHOOK_STATUS_VERIFIED,
                'last_webhook_at' => now(),
                'status'          => 'active',
            ]));

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

    private function log(PlatformConnection $conn, string $topic, string $webhookId, string $status): SyncLog
    {
        $action = in_array($topic, self::PRODUCT_TOPICS, true)
            ? 'product'
            : (in_array($topic, self::ORDER_TOPICS, true) ? 'order' : null);

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
