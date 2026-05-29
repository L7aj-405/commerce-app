<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Services\Meta\MetaMessageService;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookHandler
{
    public function __construct(
        private MetaMessageService $meta,
    ) {}

    /**
     * Extract phone, body, and timestamp from Meta's nested webhook payload.
     * Handles both plain text messages and interactive button replies.
     * Returns an empty array when no actionable message is present (e.g. status updates).
     *
     * @param  array<string, mixed>  $payload
     * @return array{phone: string, body: string, timestamp: string}|array{}
     */
    public function parsePayload(array $payload): array
    {
        $message = data_get($payload, 'entry.0.changes.0.value.messages.0');

        if (! is_array($message)) {
            return [];
        }

        $phone     = $message['from']      ?? '';
        $timestamp = $message['timestamp'] ?? '';
        $type      = $message['type']      ?? 'text';

        $body = $type === 'interactive'
            ? (string) data_get($message, 'interactive.button_reply.id', '')
            : (string) data_get($message, 'text.body', '');

        if (blank($phone) || blank($body)) {
            return [];
        }

        return compact('phone', 'body', 'timestamp');
    }

    /**
     * Find the most recent pending order whose customer phone matches $phone.
     * Normalises both sides to digits-only, then checks for suffix equality
     * to handle local vs international format differences.
     */
    public function findOrderByPhone(string $phone): ?Order
    {
        $incoming = $this->normalizePhone($phone);

        return Order::pending()
            ->whereNotNull('customer_phone')
            ->latest()
            ->get()
            ->first(fn (Order $order): bool => $this->phonesMatch($order->customer_phone, $incoming));
    }

    /**
     * Confirm or cancel the order based on the customer's reply.
     * Handles both interactive button IDs ('confirm'/'cancel') and free-text keywords.
     * Sends a follow-up text reply after updating status.
     * Unrecognised replies are silently ignored.
     */
    public function processReply(Order $order, string $message): void
    {
        $body = mb_strtolower(trim($message));

        if ($this->isConfirmation($body)) {
            $order->markAsConfirmed();
            $this->sendReply(
                $order,
                "Order #{$order->order_number} confirmed. We are now processing it and will keep you updated.",
            );
        } elseif ($this->isCancellation($body)) {
            $order->markAsCancelled();
            $this->sendReply(
                $order,
                "Order #{$order->order_number} has been cancelled as requested. Contact us if you need assistance.",
            );
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isConfirmation(string $body): bool
    {
        // 'confirm' covers the interactive button ID; text keywords cover free-text replies
        return $body === 'confirm'
            || in_array($body, ['yes', '1', 'oui', 'نعم'], true)
            || str_contains($body, 'confirm')
            || str_contains($body, 'yes');
    }

    private function isCancellation(string $body): bool
    {
        // 'cancel' covers the interactive button ID; text keywords cover free-text replies
        return $body === 'cancel'
            || in_array($body, ['no', '0', 'non', 'لا'], true)
            || str_contains($body, 'cancel')
            || str_contains($body, 'no');
    }

    private function sendReply(Order $order, string $text): void
    {
        $creds = $order->store->credentials;

        if ($creds === null || ! $creds->hasWhatsapp()) {
            return;
        }

        try {
            $this->meta->sendMessage(
                accessToken:   $creds->whatsapp_access_token,
                phoneNumberId: $creds->whatsapp_phone_number_id,
                toPhone:       $this->normalizePhone($order->customer_phone ?? ''),
                message:       $text,
            );
        } catch (\Throwable $e) {
            Log::warning('WhatsApp reply failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    private function phonesMatch(string $stored, string $incomingNormalized): bool
    {
        $normalizedStored = $this->normalizePhone($stored);

        if ($normalizedStored === $incomingNormalized) {
            return true;
        }

        // Allow country-code differences: the shorter side may have a leading 0
        // (local format like 0612345678) while the longer has the country code
        // (international like 212612345678). Strip leading zeros from the shorter
        // before checking suffix equality.
        [$shorter, $longer] = strlen($normalizedStored) <= strlen($incomingNormalized)
            ? [$normalizedStored, $incomingNormalized]
            : [$incomingNormalized, $normalizedStored];

        $subscriber = ltrim($shorter, '0');

        return strlen($subscriber) >= 9 && str_ends_with($longer, $subscriber);
    }
}
