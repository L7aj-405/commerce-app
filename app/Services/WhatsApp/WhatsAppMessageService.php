<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Services\Meta\MetaMessageService;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageService
{
    public function __construct(
        private MetaMessageService $meta,
    ) {}

    public function sendOrderConfirmation(Order $order): bool
    {
        if (! $order->canSendWhatsApp()) {
            return false;
        }

        $creds = $order->store->credentials;

        if ($creds === null || ! $creds->hasWhatsapp()) {
            Log::warning('WhatsApp not configured for store', ['store_id' => $order->store_id]);
            return false;
        }

        $templateKey = $creds->whatsapp_confirmation_tier ?? 'standard';
        $template    = MessageTemplates::get($templateKey) ?? MessageTemplates::get('standard');
        $message     = MessageTemplates::render($templateKey, $order);
        $buttons     = $template['buttons'] ?? [];

        try {
            $result = $this->meta->sendInteractive(
                accessToken:   $creds->whatsapp_access_token,      // auto-decrypted via 'encrypted' cast
                phoneNumberId: $creds->whatsapp_phone_number_id,
                toPhone:       $this->normalizePhone($order->customer_phone),
                body:          $message,
                buttons:       $buttons,
            );

            $order->update([
                'whatsapp_message_id'   => $result['message_id'],
                'whatsapp_sent_at'      => now(),
                'whatsapp_message_sent' => true,
                'confirmation_status'   => 'pending',
            ]);

            Log::info('WhatsApp confirmation sent', [
                'order_id'   => $order->id,
                'message_id' => $result['message_id'],
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::error('WhatsApp send failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function normalizePhone(string $phone): string
    {
        // Strip everything except digits
        return preg_replace('/[^0-9]/', '', $phone);
    }
}
