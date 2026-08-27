<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Connectors\Delivery\SenditConnector;
use App\Models\DeliveryConnection;
use App\Services\Delivery\SenditWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Sendit's delivery-status-update webhook. Guest route (see
 * routes/api.php) — no tenant context, so the connection is resolved
 * manually; SenditWebhookService itself runs its DB work through
 * withoutTenancy().
 */
class SenditWebhookController
{
    public function __construct(
        private readonly SenditWebhookService $webhooks,
    ) {}

    public function handle(Request $request, string $connection): JsonResponse
    {
        $conn = DeliveryConnection::withoutTenancy(
            fn () => DeliveryConnection::query()->find($connection)
        );

        if ($conn === null || $conn->provider_code !== 'sendit') {
            return response()->json(['error' => 'Unknown connection'], 404);
        }

        $rawBody = $request->getContent();
        $signature = $request->header('X-Sendit-Signature');

        $connector = new SenditConnector($conn);

        if (! $connector->verifyWebhookSignature($rawBody, $signature, $conn->credential('secret_key'))) {
            Log::warning('Sendit webhook: invalid signature', ['connection_id' => $conn->id]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if (! is_array($payload)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $result = $this->webhooks->handle($payload, $conn);

        // Always 200 for a verified, well-formed webhook — even an
        // "unknown code"/"no actor" outcome is logged server-side, not a
        // reason for Sendit to keep retrying.
        return response()->json(['status' => $result['ok'] ? 'ok' : 'ignored', 'message' => $result['message']]);
    }
}
