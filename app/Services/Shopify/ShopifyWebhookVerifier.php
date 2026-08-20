<?php

declare(strict_types=1);

namespace App\Services\Shopify;

class ShopifyWebhookVerifier
{
    /**
     * Verify a Shopify webhook's X-Shopify-Hmac-Sha256 header against the raw
     * request body, per https://shopify.dev/docs/apps/build/webhooks — HMAC is
     * computed over the raw body, the header is base64(HMAC-SHA256), and the
     * comparison must be constant-time.
     */
    public function verify(string $rawBody, ?string $hmacHeader, ?string $secret): bool
    {
        if ($hmacHeader === null || $hmacHeader === '' || $secret === null || $secret === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($computed, $hmacHeader);
    }
}
