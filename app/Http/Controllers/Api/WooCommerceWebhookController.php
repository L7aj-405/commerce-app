<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\PlatformConnection;
use App\Models\SyncLog;
use App\Services\WooCommerce\WooCommerceOrderMapper;
use App\Services\WooCommerce\WooCommerceWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single endpoint for every WooCommerce order webhook topic — mirrors
 * ShopifyWebhookController exactly (guest route, no tenant context, every
 * model call runs withoutTenancy()). The topic is read from
 * X-WC-Webhook-Topic, not the URL.
 */
class WooCommerceWebhookController
{
    // WooCommerce also fires order.deleted (order moved to trash) — never
    // routed through the mapper; deleting/cancelling a local order is a
    // confirmation/fulfillment-workflow decision, out of scope here. It's
    // simply logged and ignored (still a 200, no retry storm).
    private const ORDER_TOPICS = ['order.created', 'order.updated'];

    public function __construct(
        private readonly WooCommerceWebhookVerifier $verifier,
        private readonly WooCommerceOrderMapper $orders,
    ) {}

    public function handle(Request $request, string $connection): JsonResponse
    {
        $conn = PlatformConnection::withoutTenancy(
            fn () => PlatformConnection::query()->find($connection)
        );

        if ($conn === null) {
            return response()->json(['error' => 'Unknown connection'], 404);
        }

        if ($conn->platform !== PlatformConnection::PLATFORM_WOOCOMMERCE || $conn->status === 'disabled') {
            return response()->json(['error' => 'Connection not eligible for webhooks'], 403);
        }

        $source = $request->header('X-WC-Webhook-Source');

        if ($source === null || $conn->api_url === null || ! $this->sameSite($conn->api_url, $source)) {
            return response()->json(['error' => 'Site source mismatch'], 403);
        }

        $rawBody = $request->getContent();
        $signature = $request->header('X-WC-Webhook-Signature');

        if (! $this->verifier->verify($rawBody, $signature, $conn->webhook_secret)) {
            PlatformConnection::withoutTenancy(fn () => $conn->update(['webhook_status' => PlatformConnection::WEBHOOK_STATUS_FAILED]));

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic', '');
        $deliveryId = (string) $request->header('X-WC-Webhook-Delivery-ID', '');

        if ($deliveryId !== '') {
            $duplicate = SyncLog::withoutTenancy(fn () => SyncLog::query()
                ->where('platform_connection_id', $conn->id)
                ->where('external_id', $deliveryId)
                ->where('status', 'processed')
                ->exists());

            if ($duplicate) {
                $this->log($conn, $topic, $deliveryId, 'ignored_duplicate');

                return response()->json(['status' => 'ignored_duplicate']);
            }
        }

        $log = $this->log($conn, $topic, $deliveryId, 'verified');

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

            if (in_array($topic, self::ORDER_TOPICS, true)) {
                $this->orders->map($payload, $conn);
            } else {
                // order.deleted (or any other/unrecognized topic) — ignored,
                // never deletes the local order (that's a confirmation/
                // fulfillment-workflow decision, out of scope here).
                SyncLog::withoutTenancy(fn () => $log->update(['status' => 'ignored', 'completed_at' => now()]));

                return response()->json(['status' => 'ignored_topic']);
            }

            SyncLog::withoutTenancy(fn () => $log->update(['status' => 'processed', 'completed_at' => now()]));

            // First successfully-verified webhook is what promotes the
            // connection out of "pending" — mirrors ShopifyWebhookController.
            PlatformConnection::withoutTenancy(fn () => $conn->update([
                'webhook_status' => PlatformConnection::WEBHOOK_STATUS_VERIFIED,
                'last_webhook_at' => now(),
                'status' => 'active',
            ]));

            return response()->json(['status' => 'ok']);
        } catch (Throwable $e) {
            Log::error('WooCommerce webhook processing failed', [
                'connection' => $conn->id,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            SyncLog::withoutTenancy(fn () => $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]));

            // Still 200 — a bad local mapping shouldn't make WooCommerce
            // hammer retries; the failure is fully logged for follow-up.
            return response()->json(['status' => 'accepted_but_failed']);
        }
    }

    /** Loose match: WooCommerce's X-WC-Webhook-Source is the site's home URL — compare scheme/www-agnostic host+path, not byte-for-byte. */
    private function sameSite(string $apiUrl, string $source): bool
    {
        $normalize = static function (string $url): string {
            $url = strtolower(trim($url));
            $url = preg_replace('#^https?://#', '', $url) ?? $url;
            $url = preg_replace('#^www\.#', '', $url) ?? $url;

            return rtrim($url, '/');
        };

        return hash_equals($normalize($apiUrl), $normalize($source));
    }

    private function log(PlatformConnection $conn, string $topic, string $deliveryId, string $status): SyncLog
    {
        return SyncLog::withoutTenancy(fn () => SyncLog::create([
            'store_id' => $conn->store_id,
            'platform_connection_id' => $conn->id,
            'platform' => $conn->platform,
            'direction' => 'pull',
            'action' => 'order',
            'external_id' => $deliveryId !== '' ? $deliveryId : null,
            'type' => 'webhook:' . $topic,
            'status' => $status,
            'started_at' => now(),
        ]));
    }
}
