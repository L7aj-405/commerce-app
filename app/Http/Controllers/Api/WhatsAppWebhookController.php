<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\StoreCredential;
use App\Services\WhatsAppWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController
{
    public function __construct(
        private WhatsAppWebhookHandler $webhookHandler,
    ) {}

    /**
     * Handle Meta webhook verification GET request (hub challenge).
     * PHP converts dots in query param names to underscores, so hub.mode → hub_mode.
     */
    public function verify(Request $request): Response|JsonResponse
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge', '');

        if ($mode !== 'subscribe') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // whatsapp_webhook_verify_token is encrypted in the DB, so we must
        // decrypt each row in PHP rather than compare in SQL.
        $matched = StoreCredential::whereNotNull('whatsapp_webhook_verify_token')
            ->get()
            ->contains(fn (StoreCredential $c): bool => $c->whatsapp_webhook_verify_token === $token);

        if (! $matched) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response($challenge);
    }

    /**
     * Handle incoming WhatsApp webhook event (POST).
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return response()->json(['status' => 'ignored']);
        }

        $parsed = $this->webhookHandler->parsePayload($payload);

        if (empty($parsed)) {
            return response()->json(['status' => 'ok']);
        }

        $order = $this->webhookHandler->findOrderByPhone($parsed['phone']);

        if ($order === null) {
            return response()->json(['status' => 'ok']);
        }

        try {
            $this->webhookHandler->processReply($order, $parsed['body']);
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook processing failed', [
                'error'    => $e->getMessage(),
                'order_id' => $order->id,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
