<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Connectors\ShopifyConnector;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates and caches short-lived Shopify Admin API tokens via the
 * client_credentials grant, for connections using
 * PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS.
 *
 * client_id lives in the existing `consumer_key` column, client_secret in
 * the existing (already encrypted-cast) `consumer_secret` column — no new
 * columns. The generated token is never persisted, only cached.
 */
class ShopifyAuthService
{
    private const CACHE_PREFIX = 'shopify:connection:';
    private const CACHE_SUFFIX = ':admin_token';
    private const SAFETY_BUFFER_SECONDS = 300;
    private const MIN_TTL_SECONDS = 60;

    /**
     * store-name.myshopify.com — strips scheme/trailing slash, rejects the
     * admin.shopify.com/store/... URL format with a specific message.
     */
    public function normalizeShopDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        if ($domain === '') {
            throw new ShopifyAuthException('Enter a shop domain.');
        }

        if (str_contains($domain, 'admin.shopify.com')) {
            throw new ShopifyAuthException(
                'That looks like the admin.shopify.com URL. Enter the store domain instead, e.g. "your-store.myshopify.com".'
            );
        }

        if (! str_ends_with($domain, '.myshopify.com')) {
            throw new ShopifyAuthException('Shop domain must look like "your-store.myshopify.com".');
        }

        return $domain;
    }

    /** Cached token if present and fresh, otherwise generates a new one. */
    public function getToken(PlatformConnection $connection): string
    {
        $cached = Cache::get($this->cacheKey($connection));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->generateAndCacheToken($connection)['access_token'];
    }

    /**
     * @return array{access_token: string, scope: string, expires_in: int}
     */
    public function generateAndCacheToken(PlatformConnection $connection): array
    {
        $result = $this->requestToken($connection);

        $ttl = max(self::MIN_TTL_SECONDS, $result['expires_in'] - self::SAFETY_BUFFER_SECONDS);
        Cache::put($this->cacheKey($connection), $result['access_token'], $ttl);

        return $result;
    }

    /** Exact wording the verification panel relies on — never show a green
     *  badge alongside one of these, and never leave a stale one behind. */
    private const MESSAGE_CONNECTED = 'Connected successfully. Products API is reachable.';
    private const MESSAGE_MISSING_SCOPE = 'Missing read_products scope or app version not released with required scopes.';
    private const MESSAGE_INVALID_CREDENTIALS = 'Invalid Client ID or Client Secret.';
    private const MESSAGE_INVALID_DOMAIN = 'Invalid shop domain. Use store-name.myshopify.com.';

    /**
     * Forces a fresh token (bypassing the cache), verifies read_products is
     * granted, then makes a real GET /products.json?limit=1 call — the full
     * check "Test connection" needs to give an honest answer. Every branch
     * persists its own final token_status/last_token_error via
     * markValid()/markFailed() so the two can never disagree with what this
     * method returns — there is exactly one source of truth per test run.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(PlatformConnection $connection): array
    {
        Cache::forget($this->cacheKey($connection));

        // Domain format is checked on its own, first, so it gets the exact
        // wording the panel expects rather than whatever requestToken()'s
        // HTTP call happens to fail with.
        try {
            $this->normalizeShopDomain((string) $connection->shop_domain);
        } catch (ShopifyAuthException) {
            $this->markFailed($connection, self::MESSAGE_INVALID_DOMAIN);

            return ['ok' => false, 'message' => self::MESSAGE_INVALID_DOMAIN];
        }

        try {
            $token = $this->generateAndCacheToken($connection);
        } catch (ShopifyAuthException $e) {
            // requestToken() already persisted the specific failure reason.
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! str_contains($token['scope'], 'read_products')) {
            $this->markFailed($connection, self::MESSAGE_MISSING_SCOPE);

            return ['ok' => false, 'message' => self::MESSAGE_MISSING_SCOPE];
        }

        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token['access_token']])
                ->acceptJson()
                ->timeout(30)
                ->get($this->baseUrl($connection) . '/products.json', ['limit' => 1]);
        } catch (\Throwable) {
            $this->markFailed($connection, self::MESSAGE_INVALID_DOMAIN);

            return ['ok' => false, 'message' => self::MESSAGE_INVALID_DOMAIN];
        }

        if ($response->status() === 403) {
            $this->markFailed($connection, self::MESSAGE_MISSING_SCOPE);

            return ['ok' => false, 'message' => self::MESSAGE_MISSING_SCOPE];
        }

        if ($response->status() === 401) {
            $this->markFailed($connection, self::MESSAGE_INVALID_CREDENTIALS);

            return ['ok' => false, 'message' => self::MESSAGE_INVALID_CREDENTIALS];
        }

        if (! $response->successful()) {
            $message = "Shopify returned HTTP {$response->status()}.";
            $this->markFailed($connection, $message);

            return ['ok' => false, 'message' => $message];
        }

        // Genuinely verified end to end — the only branch allowed to leave
        // the connection in a "valid" state.
        $this->markValid($connection);

        return ['ok' => true, 'message' => self::MESSAGE_CONNECTED];
    }

    /**
     * @return array{access_token: string, scope: string, expires_in: int}
     */
    private function requestToken(PlatformConnection $connection): array
    {
        $domain = $this->normalizeShopDomain((string) $connection->shop_domain);
        $clientId = (string) $connection->consumer_key;
        $clientSecret = (string) $connection->consumer_secret;

        if ($clientId === '' || $clientSecret === '') {
            $this->markFailed($connection, 'Client ID and Client Secret are required.');
            throw new ShopifyAuthException('Client ID and Client Secret are required.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->post("https://{$domain}/admin/oauth/access_token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            // Never include $clientSecret/token in the log — connection id and
            // exception message only.
            Log::warning('Shopify token request failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
            $this->markFailed($connection, 'Could not reach Shopify.');
            throw new ShopifyAuthException('Could not reach Shopify: ' . $e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $this->markFailed($connection, self::MESSAGE_INVALID_CREDENTIALS);
            throw new ShopifyAuthException(self::MESSAGE_INVALID_CREDENTIALS);
        }

        if (! $response->successful()) {
            $this->markFailed($connection, "Shopify returned HTTP {$response->status()}.");
            throw new ShopifyAuthException("Shopify token request failed: HTTP {$response->status()}.");
        }

        $data = $response->json();
        $accessToken = is_array($data) ? ($data['access_token'] ?? null) : null;
        $scope = is_array($data) ? (string) ($data['scope'] ?? '') : '';
        $expiresIn = is_array($data) ? (int) ($data['expires_in'] ?? 3600) : 3600;

        if (! is_string($accessToken) || $accessToken === '') {
            $this->markFailed($connection, 'Shopify did not return an access token.');
            throw new ShopifyAuthException('Shopify did not return an access token.');
        }

        if (trim($scope) === '') {
            $this->markFailed($connection, 'Token generated but has no scopes.');
            throw new ShopifyAuthException(
                'Token generated but has no scopes. Release/update the app version with Admin API scopes, then generate again.'
            );
        }

        $this->markValid($connection);

        return ['access_token' => $accessToken, 'scope' => $scope, 'expires_in' => $expiresIn];
    }

    private function baseUrl(PlatformConnection $connection): string
    {
        return 'https://' . $this->normalizeShopDomain((string) $connection->shop_domain) . '/admin/api/' . ShopifyConnector::API_VERSION;
    }

    private function cacheKey(PlatformConnection $connection): string
    {
        return self::CACHE_PREFIX . $connection->id . self::CACHE_SUFFIX;
    }

    private function markValid(PlatformConnection $connection): void
    {
        $connection->update([
            'settings' => array_merge($connection->settings ?? [], [
                'token_status' => 'valid',
                'last_token_generated_at' => now()->toIso8601String(),
                'last_token_error' => null,
            ]),
        ]);
    }

    private function markFailed(PlatformConnection $connection, string $error): void
    {
        $connection->update([
            'settings' => array_merge($connection->settings ?? [], [
                'token_status' => 'failed',
                'last_token_error' => $error,
            ]),
        ]);
    }
}
