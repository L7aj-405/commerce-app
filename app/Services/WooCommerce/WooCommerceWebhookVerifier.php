<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

class WooCommerceWebhookVerifier
{
    /**
     * Verify a WooCommerce webhook's X-WC-Webhook-Signature header against
     * the raw request body, per WooCommerce's own webhook docs — same
     * algorithm as Shopify's: base64(HMAC-SHA256(raw body, secret)),
     * constant-time comparison. Kept as its own class (not a re-use of
     * ShopifyWebhookVerifier) so each platform's webhook stack stays fully
     * self-contained under its own app/Services/{Platform}/ namespace.
     */
    public function verify(string $rawBody, ?string $signatureHeader, ?string $secret): bool
    {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === null || $secret === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($computed, $signatureHeader);
    }
}
