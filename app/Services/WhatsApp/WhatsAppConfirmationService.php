<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class WhatsAppConfirmationService
{
    public function __construct(
        private EvolutionApiService $evolutionApi,
        private AIResponseAnalyzerService $analyzer,
        private ActionRouterService $router,
    ) {}

    /**
     * Initiate order confirmation
     * 
     */

     public function initiateOrderConfirmation(Order $order): void
{
    // Check which provider (meta or evolution)
    $provider = $order->store->credentials->whatsapp_provider;

    if ($provider === 'meta') {
        $this->sendViaMetaAPI($order);
    } elseif ($provider === 'evolution') {
        $this->sendViaEvolutionAPI($order);
    }
}

private function sendViaMetaAPI(Order $order): void
{
    $metaService = new \App\Services\Meta\MetaMessageService();
    
    $accessToken = decrypt($order->store->credentials->meta_access_token);
    $phoneNumberId = $order->store->credentials->whatsapp_phone_number_id;
    
    $message = "Hi {$order->customer_name}!\n\nYour order #{$order->order_number} for {$order->total} {$order->currency}\n\nReply YES to confirm or NO to cancel";
    
    $result = $metaService->sendMessage(
        accessToken: $accessToken,
        phoneNumberId: $phoneNumberId,
        toPhone: $this->formatPhoneForMeta($order->customer_phone),
        message: $message,
    );
    
    $order->update([
        'whatsapp_sent_at' => now(),
        'whatsapp_message_id' => $result['message_id'],
        'confirmation_status' => 'pending',
    ]);
}

private function formatPhoneForMeta(string $phone): string
{
    // Remove +, spaces, etc
    return preg_replace('/[^0-9]/', '', $phone);
}
    

    /**
     * Handle customer reply
     */
    public function handleCustomerReply(Order $order, string $rawReply): void
    {
        try {
            // Analyze reply
            $analysis = $this->analyzer->analyzeCustomerResponse($order, $rawReply);

            // Route action
            $this->router->routeAction($order, $analysis);

        } catch (\Exception $e) {
            Log::error('Failed to handle customer reply', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send simple message (template)
     */
    private function sendSimple(Order $order): void
    {
        $message = "Hi {$order->customer_name}! 👋\n\nYour order #{$order->order_number} for {$order->total} {$order->currency} is confirmed.\n\nReply:\n- YES to confirm\n- NO to cancel";

        $result = $this->evolutionApi->sendMessage($order->customer_phone, $message, $order->id);

        $order->update([
            'whatsapp_sent_at' => now(),
            'whatsapp_message_id' => $result['message_id'],
            'confirmation_status' => 'pending',
            'confirmation_tier' => 'simple',
        ]);

        Log::info('Simple confirmation sent', ['order_id' => $order->id]);
    }

    /**
     * Send smart message (AI personalized)
     */
    private function sendSmart(Order $order): void
    {
        // For now, just use simple message
        // Later: call AI to personalize
        $this->sendSimple($order);
    }
}