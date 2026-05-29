<?php

declare(strict_types=1);

namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaMessageService
{
    private const BASE_URL = 'https://graph.facebook.com/v18.0';

    /**
     * Send a WhatsApp interactive message with quick-reply buttons.
     */
    public function sendInteractive(
        string $accessToken,
        string $phoneNumberId,
        string $toPhone,
        string $body,
        array  $buttons = [],
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $toPhone,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $body],
                'action' => [
                    'buttons' => array_map(
                        static fn (array $btn, int $i): array => [
                            'type'  => 'reply',
                            'reply' => [
                                'id'    => $btn['id'] ?? "btn_{$i}",
                                'title' => $btn['title'],
                            ],
                        ],
                        $buttons,
                        array_keys($buttons),
                    ),
                ],
            ],
        ];

        $response = Http::withToken($accessToken)
            ->post(self::BASE_URL . "/{$phoneNumberId}/messages", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException("Meta API error: {$response->body()}");
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
            'timestamp'  => now(),
        ];
    }

    /**
     * Send WhatsApp message via Meta API
     */
    public function sendMessage(
        string $accessToken,
        string $phoneNumberId,
        string $toPhone,
        string $message,
    ): array {
        try {
            $response = Http::withToken($accessToken)
                ->post(self::BASE_URL . "/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $toPhone, // Should be in format: 212612345678 (no +)
                    'type' => 'text',
                    'text' => [
                        'body' => $message,
                    ],
                ]);

            if (!$response->successful()) {
                throw new \Exception("Meta API error: {$response->body()}");
            }

            $messageId = $response->json('messages.0.id');

            Log::info('WhatsApp message sent via Meta', [
                'to' => $toPhone,
                'message_id' => $messageId,
            ]);

            return [
                'success' => true,
                'message_id' => $messageId,
                'timestamp' => now(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message via Meta', [
                'to' => $toPhone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send message template (pre-approved by Meta)
     * Useful for notifications, confirmations, etc
     */
    public function sendTemplate(
        string $accessToken,
        string $phoneNumberId,
        string $toPhone,
        string $templateName,
        array $parameters = [],
    ): array {
        $response = Http::withToken($accessToken)
            ->post(self::BASE_URL . "/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $toPhone,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => 'en_US',
                    ],
                    'parameters' => [
                        'body' => [
                            'parameters' => $parameters,
                        ],
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Meta API error: {$response->body()}");
        }

        return [
            'success' => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }
}