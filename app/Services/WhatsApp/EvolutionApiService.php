<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvolutionApiService
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $instanceName,
    ) {}

    /**
     * Send a WhatsApp message
     */
    public function sendMessage(string $phone, string $message, ?string $orderId = null): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->post("{$this->baseUrl}/message/sendText", [
                'number' => $phone,
                'text' => $message,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Evolution API error: {$response->body()}");
            }

            $messageId = $response->json('key.id') ?? uniqid('msg_');

            Log::info('WhatsApp message sent', [
                'phone' => $phone,
                'message_id' => $messageId,
                'order_id' => $orderId,
            ]);

            return [
                'success' => true,
                'message_id' => $messageId,
                'timestamp' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message', [
                'phone' => $phone,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if Evolution API is connected
     */
    public function isConnected(): bool
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get("{$this->baseUrl}/instance/info");

            return $response->successful();

        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Get instance name
     */
    public function getInstanceName(): string
    {
        return $this->instanceName;
    }
}