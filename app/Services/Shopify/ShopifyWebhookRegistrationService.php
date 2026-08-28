<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Connectors\ShopifyConnector;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ensures a Shopify connection's order webhooks (orders/create,
 * orders/updated, orders/cancelled) are actually registered with Shopify's
 * Admin API, and records a per-topic status the connection profile shows
 * ("active"/"failed"/"unknown") instead of silently assuming automatic
 * import works. registerWebhook() is idempotent (Shopify's 422 "already
 * taken" is treated as success), so this is safe to call repeatedly —
 * after saving credentials, after "Test connection", or as an explicit
 * repair action.
 */
class ShopifyWebhookRegistrationService
{
    public const TOPICS = ['orders/create', 'orders/updated', 'orders/cancelled'];

    /**
     * @return array{topics: array<string, string>, checked_at: string, error: ?string}
     */
    public function sync(PlatformConnection $connection): array
    {
        $address = url("/api/webhooks/shopify/{$connection->id}");
        $connector = new ShopifyConnector($connection);

        try {
            $existingTopics = collect($connector->listWebhooks())
                ->filter(fn (array $w) => $w['address'] === $address)
                ->pluck('topic')
                ->all();
        } catch (Throwable $e) {
            Log::warning('Shopify webhook registration: could not list existing webhooks', [
                'connection' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return $this->persist($connection, array_fill_keys(self::TOPICS, 'unknown'), $e->getMessage());
        }

        $topics = [];
        $error = null;

        foreach (self::TOPICS as $topic) {
            if (in_array($topic, $existingTopics, true)) {
                $topics[$topic] = 'active';

                continue;
            }

            try {
                $registration = $connector->registerWebhook($topic, $address);
                $topics[$topic] = $registration['success'] ? 'active' : 'failed';

                if (! $registration['success']) {
                    $error ??= $registration['message'];
                    Log::warning('Shopify webhook registration failed for topic', [
                        'connection' => $connection->id,
                        'topic' => $topic,
                        'message' => $registration['message'],
                    ]);
                }
            } catch (Throwable $e) {
                $topics[$topic] = 'failed';
                $error ??= $e->getMessage();
                Log::warning('Shopify webhook registration threw for topic', [
                    'connection' => $connection->id,
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->persist($connection, $topics, $error);
    }

    /**
     * @param  array<string, string>  $topics
     * @return array{topics: array<string, string>, checked_at: string, error: ?string}
     */
    private function persist(PlatformConnection $connection, array $topics, ?string $error): array
    {
        $result = ['topics' => $topics, 'checked_at' => now()->toIso8601String(), 'error' => $error];

        $connection->update([
            'metadata' => array_merge($connection->metadata ?? [], ['webhooks' => $result]),
        ]);

        return $result;
    }
}
