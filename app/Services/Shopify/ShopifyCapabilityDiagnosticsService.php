<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Connectors\ShopifyConnector;
use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Http;

/**
 * Real-API-truth diagnostics for a Shopify admin_client_credentials
 * connection — replaces relying on the token's self-reported `scope`
 * string as a hard pass/fail gate (that was the bug: a connection whose
 * scope string didn't literally contain "read_products" was marked
 * Failed even when GET /products.json actually returned 200).
 *
 * Read capabilities are always verified with a real, safe (non-mutating)
 * Shopify Admin API call. Write capabilities are never mutation-tested —
 * they are reported as "configured"/"not_configured" purely from the
 * token's reported scopes, per the task's explicit safety requirement.
 *
 * Does not touch ShopifyAuthService at all (task: "do not rewrite Shopify
 * auth architecture") — only reuses its public generateAndCacheToken()/
 * normalizeShopDomain().
 */
class ShopifyCapabilityDiagnosticsService
{
    private const READ_CAPABILITIES = [
        'shop.read' => ['label' => 'Shop access', 'path' => '/shop.json', 'query' => [], 'success' => 'Shop API reachable.', 'forbidden' => 'Missing shop access — check credentials or app installation.'],
        'products.read' => ['label' => 'Read products', 'path' => '/products.json', 'query' => ['limit' => 1], 'success' => 'Products API reachable.', 'forbidden' => 'Missing read_products scope or app version not released with required scopes.'],
        'orders.read' => ['label' => 'Read orders', 'path' => '/orders.json', 'query' => ['limit' => 1, 'status' => 'any'], 'success' => 'Orders API reachable.', 'forbidden' => 'Missing read_orders scope or order access not granted.'],
        'locations.read' => ['label' => 'Read locations', 'path' => '/locations.json', 'query' => [], 'success' => 'Locations API reachable.', 'forbidden' => 'Missing read_locations scope.'],
    ];

    private const WRITE_CAPABILITIES = [
        'products.write' => ['label' => 'Write products', 'scope' => 'write_products'],
        'orders.write' => ['label' => 'Write orders', 'scope' => 'write_orders'],
        'inventory.write' => ['label' => 'Write inventory', 'scope' => 'write_inventory'],
    ];

    public function __construct(private readonly ShopifyAuthService $auth) {}

    /**
     * @return array{
     *   status: string, shop_domain: ?string, last_checked_at: string,
     *   token: array{generated: bool, expires_in: ?int, reported_scopes: array<int,string>},
     *   capabilities: array<int, array{key:string,label:string,status:string,message:string}>,
     * }
     */
    public function run(PlatformConnection $connection): array
    {
        try {
            $this->auth->normalizeShopDomain((string) $connection->shop_domain);
        } catch (ShopifyAuthException $e) {
            return $this->persist($connection, $this->skippedReport($connection, $e->getMessage()));
        }

        try {
            $token = $this->auth->generateAndCacheToken($connection);
        } catch (ShopifyAuthException $e) {
            return $this->persist($connection, $this->skippedReport($connection, $e->getMessage()));
        }

        $domain = $this->auth->normalizeShopDomain((string) $connection->shop_domain);
        $baseUrl = "https://{$domain}/admin/api/" . ShopifyConnector::API_VERSION;
        $reportedScopes = array_values(array_filter(array_map('trim', explode(',', $token['scope']))));

        $capabilities = [];
        foreach (self::READ_CAPABILITIES as $key => $def) {
            $capabilities[] = $this->checkRead($baseUrl, $token['access_token'], $key, $def);
        }

        $capabilities[] = [
            'key' => 'inventory.read',
            'label' => 'Read inventory',
            'status' => 'not_tested',
            'message' => 'Not tested — no safe inventory endpoint configured.',
        ];

        foreach (self::WRITE_CAPABILITIES as $key => $def) {
            $capabilities[] = $this->writeCapability($key, $def['label'], $def['scope'], $reportedScopes);
        }

        $byKey = collect($capabilities)->keyBy('key');
        $shopPassed = $byKey->get('shop.read')['status'] === 'passed';
        $productsPassed = $byKey->get('products.read')['status'] === 'passed';
        $anyOtherReadFailed = collect(self::READ_CAPABILITIES)
            ->keys()
            ->reject(fn ($key) => in_array($key, ['shop.read', 'products.read'], true))
            ->contains(fn ($key) => $byKey->get($key)['status'] === 'failed');

        // "Do not show Failed if products import works" — products.read
        // passing always keeps the connection out of the failed bucket,
        // even if shop.read itself somehow didn't.
        $status = match (true) {
            $productsPassed && $shopPassed && ! $anyOtherReadFailed => 'connected',
            $productsPassed => 'partially_configured',
            $shopPassed => 'partially_configured',
            default => 'failed',
        };

        $report = [
            'status' => $status,
            'shop_domain' => $domain,
            'last_checked_at' => now()->toIso8601String(),
            'token' => [
                'generated' => true,
                'expires_in' => $token['expires_in'],
                'reported_scopes' => $reportedScopes,
            ],
            'capabilities' => $capabilities,
        ];

        return $this->persist($connection, $report, clearStaleTokenError: $productsPassed);
    }

    private function checkRead(string $baseUrl, string $accessToken, string $key, array $def): array
    {
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->acceptJson()
                ->timeout(20)
                ->get($baseUrl . $def['path'], $def['query']);
        } catch (\Throwable $e) {
            return ['key' => $key, 'label' => $def['label'], 'status' => 'failed', 'message' => 'Could not reach Shopify: ' . $e->getMessage()];
        }

        if ($response->status() === 403) {
            return ['key' => $key, 'label' => $def['label'], 'status' => 'failed', 'message' => $def['forbidden']];
        }

        if ($response->status() === 401) {
            return ['key' => $key, 'label' => $def['label'], 'status' => 'failed', 'message' => 'Shopify rejected the request (401) — invalid or expired token.'];
        }

        if (! $response->successful()) {
            return ['key' => $key, 'label' => $def['label'], 'status' => 'failed', 'message' => "Shopify returned HTTP {$response->status()}."];
        }

        return ['key' => $key, 'label' => $def['label'], 'status' => 'passed', 'message' => $def['success']];
    }

    /** @param array<int, string> $reportedScopes */
    private function writeCapability(string $key, string $label, string $scopeName, array $reportedScopes): array
    {
        if (in_array($scopeName, $reportedScopes, true)) {
            return ['key' => $key, 'label' => $label, 'status' => 'configured', 'message' => "{$scopeName} scope is configured. No test mutation was performed."];
        }

        return ['key' => $key, 'label' => $label, 'status' => 'not_configured', 'message' => "{$scopeName} not reported by token."];
    }

    /**
     * Token could not even be generated — every capability is unreachable,
     * not "failed" (nothing was actually tested). shop.read carries the
     * real reason (invalid domain/credentials); the rest just note that
     * they were never reached, so the reason isn't repeated eight times.
     */
    private function skippedReport(PlatformConnection $connection, string $reason): array
    {
        $capabilities = [];
        $first = true;

        foreach (self::READ_CAPABILITIES as $key => $def) {
            $capabilities[] = [
                'key' => $key,
                'label' => $def['label'],
                'status' => 'skipped',
                'message' => $first ? $reason : 'Not tested — token generation failed.',
            ];
            $first = false;
        }

        $capabilities[] = ['key' => 'inventory.read', 'label' => 'Read inventory', 'status' => 'skipped', 'message' => 'Not tested — token generation failed.'];

        foreach (self::WRITE_CAPABILITIES as $key => $def) {
            $capabilities[] = ['key' => $key, 'label' => $def['label'], 'status' => 'skipped', 'message' => 'Not tested — token generation failed.'];
        }

        return [
            'status' => 'failed',
            'shop_domain' => $connection->shop_domain,
            'last_checked_at' => now()->toIso8601String(),
            'token' => ['generated' => false, 'expires_in' => null, 'reported_scopes' => []],
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Stores the report (never a secret/token — the report shape itself
     * only ever carries scope names, statuses, and messages) in the
     * existing settings JSON column, alongside the legacy token_status/
     * last_token_error keys ShopifyAuthService::testConnection() also
     * writes — cleared here too when products.read just passed, so an old
     * "missing read_products" error can never sit stale next to a fresh
     * successful diagnostics run.
     */
    private function persist(PlatformConnection $connection, array $report, bool $clearStaleTokenError = false): array
    {
        $settings = array_merge($connection->settings ?? [], ['diagnostics' => $report]);

        if ($clearStaleTokenError) {
            $settings['token_status'] = 'valid';
            $settings['last_token_error'] = null;
        }

        $connection->update(['settings' => $settings]);

        return $report;
    }
}
