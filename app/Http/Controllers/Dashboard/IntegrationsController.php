<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Connectors\ShopifyConnector;
use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use App\Services\Shopify\ShopifyAuthException;
use App\Services\Shopify\ShopifyAuthService;
use App\Services\Shopify\ShopifyCapabilityDiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IntegrationsController extends Controller
{
    /** Topics currently wired up end to end (Shopify Integration Workflow Upgrade). */
    private const SHOPIFY_WEBHOOK_EVENTS = ['orders/create', 'orders/updated', 'products/create', 'products/update'];

    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        $connections = $store === null ? collect() : PlatformConnection::query()
            ->where('store_id', $store->id)
            ->get(['id', 'platform', 'status', 'label', 'last_synced_at', 'synced_products_count', 'synced_orders_count']);

        $providers = [
            ['key' => 'woocommerce', 'name' => 'WooCommerce', 'description' => 'WordPress-based stores'],
            ['key' => 'shopify',     'name' => 'Shopify',     'description' => 'Shopify storefronts'],
            ['key' => 'youcan',      'name' => 'YouCan',      'description' => 'YouCan Shop'],
            ['key' => 'whatsapp',    'name' => 'WhatsApp',    'description' => 'Order confirmations via WhatsApp'],
        ];

        return Inertia::render('Dashboard/Integrations/Index', [
            'store'       => $store?->only(['id', 'name']),
            'providers'   => $providers,
            'connections' => $connections,
        ]);
    }

    public function woocommerce(Request $request): Response
    {
        return Inertia::render('Dashboard/Integrations/Platforms/WooCommerce', [
            'connection' => $this->connectionFor($request, 'woocommerce'),
        ]);
    }

    public function shopify(Request $request): Response
    {
        $store = $request->user()->getActiveStore();
        $conn  = $store === null ? null : PlatformConnection::query()
            ->where('store_id', $store->id)
            ->where('platform', 'shopify')
            ->first();

        $connection = $conn?->only([
            'id', 'status', 'connection_method', 'shop_domain',
            'webhook_status', 'last_webhook_at',
            'last_synced_at', 'synced_products_count', 'synced_orders_count',
        ]);

        if ($connection !== null) {
            $connection['has_access_token']  = filled($conn->access_token);
            $connection['has_webhook_secret'] = filled($conn->webhook_secret);
            $connection['webhook_events']     = $conn->settings['webhook_events'] ?? [];

            // Admin API via client credentials — client_id isn't secret (shown
            // back, like WooCommerce's consumer_key already is); client_secret
            // never leaves the backend, only whether one is saved.
            $connection['client_id']               = $conn->consumer_key;
            $connection['has_client_secret']        = filled($conn->consumer_secret);
            $connection['token_status']             = $conn->settings['token_status'] ?? 'unknown';
            $connection['last_token_error']         = $conn->settings['last_token_error'] ?? null;
            $connection['last_token_generated_at']  = $conn->settings['last_token_generated_at'] ?? null;
            // Never includes client_secret/access_token — ShopifyCapabilityDiagnosticsService
            // only ever persists statuses, messages, and reported scope names here.
            $connection['diagnostics']              = $conn->settings['diagnostics'] ?? null;
        }

        return Inertia::render('Dashboard/Integrations/Platforms/Shopify', [
            'connection'   => $connection,
            'webhookUrl'   => $conn !== null ? url("/api/webhooks/shopify/{$conn->id}") : null,
            'webhookEvents'=> self::SHOPIFY_WEBHOOK_EVENTS,
        ]);
    }

    public function youcan(Request $request): Response
    {
        return Inertia::render('Dashboard/Integrations/Platforms/YouCan', [
            'connection' => $this->connectionFor($request, 'youcan'),
        ]);
    }

    public function whatsapp(Request $request): Response
    {
        return Inertia::render('Dashboard/Integrations/Platforms/WhatsApp', [
            'connection' => $this->connectionFor($request, 'whatsapp'),
        ]);
    }

    public function saveWoocommerce(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'api_url'         => ['required', 'url'],
            'consumer_key'    => ['required', 'string'],
            'consumer_secret' => ['required', 'string'],
        ]);
        $this->saveConnection($request, 'woocommerce', $validated);
        return redirect()->route('dashboard.integrations.woocommerce')->with('success', 'WooCommerce connected.');
    }

    public function saveShopify(Request $request): \Illuminate\Http\RedirectResponse
    {
        $method = $request->input('connection_method', PlatformConnection::CONNECTION_METHOD_ADMIN_TOKEN);

        if ($method === PlatformConnection::CONNECTION_METHOD_WEBHOOK) {
            $validated = $request->validate([
                'shop_domain'    => ['required', 'string'],
                'webhook_secret' => ['nullable', 'string'],
                'events'         => ['required', 'array', 'min:1'],
                'events.*'       => [Rule::in(self::SHOPIFY_WEBHOOK_EVENTS)],
            ]);

            $store = $request->user()->getActiveStore();
            abort_if($store === null, 422, 'No active store.');

            $existing = PlatformConnection::query()
                ->where('store_id', $store->id)
                ->where('platform', 'shopify')
                ->first();

            $data = [
                'connection_method' => PlatformConnection::CONNECTION_METHOD_WEBHOOK,
                'shop_domain'       => $validated['shop_domain'],
                // A blank secret means "keep the existing one" — never blank it
                // out just because the field was left empty on a re-save.
                'webhook_secret'    => filled($validated['webhook_secret'] ?? null)
                    ? $validated['webhook_secret']
                    : $existing?->webhook_secret,
                'settings'          => array_merge($existing?->settings ?? [], ['webhook_events' => $validated['events']]),
                // Never active until a real webhook verifies (see ShopifyWebhookController).
                'status'            => 'pending',
                'webhook_status'    => PlatformConnection::WEBHOOK_STATUS_PENDING,
            ];

            PlatformConnection::updateOrCreate(
                ['store_id' => $store->id, 'platform' => 'shopify'],
                $data,
            );

            return redirect()->route('dashboard.integrations.shopify')->with('success', 'Webhook setup saved — paste the URL into Shopify and send a test webhook.');
        }

        if ($method === PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS) {
            $validated = $request->validate([
                'shop_domain'   => ['required', 'string'],
                'client_id'     => ['required', 'string'],
                'client_secret' => ['nullable', 'string'],
            ]);

            $store = $request->user()->getActiveStore();
            abort_if($store === null, 422, 'No active store.');

            try {
                $domain = app(ShopifyAuthService::class)->normalizeShopDomain($validated['shop_domain']);
            } catch (ShopifyAuthException $e) {
                throw ValidationException::withMessages(['shop_domain' => $e->getMessage()]);
            }

            $existing = PlatformConnection::query()
                ->where('store_id', $store->id)
                ->where('platform', 'shopify')
                ->first();

            PlatformConnection::updateOrCreate(
                ['store_id' => $store->id, 'platform' => 'shopify'],
                [
                    'connection_method' => PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS,
                    'shop_domain'       => $domain,
                    'consumer_key'      => $validated['client_id'],
                    // Blank secret means "keep the existing one" — same rule
                    // already used for the webhook signing secret.
                    'consumer_secret'   => filled($validated['client_secret'] ?? null)
                        ? $validated['client_secret']
                        : $existing?->consumer_secret,
                    // New/changed credentials invalidate any previous
                    // verdict — token_status/last_token_error/diagnostics are
                    // all cleared so a stale "missing read_products" (or any
                    // other) error can never survive a credential edit;
                    // Test connection must be run again to earn a fresh one.
                    'settings'          => array_merge(
                        collect($existing?->settings ?? [])->except(['last_token_error', 'diagnostics'])->all(),
                        ['token_status' => 'unknown'],
                    ),
                    'metadata'          => collect($existing?->metadata ?? [])->except(['auth_check'])->all(),
                    'status'            => 'active',
                ],
            );

            return redirect()->route('dashboard.integrations.shopify')->with('success', 'Shopify credentials saved — use "Test connection" to generate a token.');
        }

        $validated = $request->validate([
            'shop_domain'  => ['required', 'string'],
            'access_token' => ['required', 'string'],
        ]);
        $this->saveConnection($request, 'shopify', array_merge($validated, [
            'connection_method' => PlatformConnection::CONNECTION_METHOD_ADMIN_TOKEN,
        ]));
        return redirect()->route('dashboard.integrations.shopify')->with('success', 'Shopify connected.');
    }

    public function saveYoucan(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string'],
            'api_url'      => ['nullable', 'url'],
        ]);
        $this->saveConnection($request, 'youcan', $validated);
        return redirect()->route('dashboard.integrations.youcan')->with('success', 'YouCan connected.');
    }

    public function saveWhatsapp(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'access_token'   => ['required', 'string'],
            'shop_domain'    => ['nullable', 'string'], // reusing column for phone_number_id
            'webhook_secret' => ['nullable', 'string'],
        ]);
        $this->saveConnection($request, 'whatsapp', $validated);
        return redirect()->route('dashboard.integrations.whatsapp')->with('success', 'WhatsApp connected.');
    }

    public function testConnection(Request $request, string $platform): \Illuminate\Http\JsonResponse
    {
        $store = $request->user()->getActiveStore();
        $conn  = $store === null ? null : PlatformConnection::query()
            ->where('store_id', $store->id)
            ->where('platform', $platform)
            ->first();

        if ($conn === null) {
            return response()->json(['ok' => false, 'message' => 'No connection configured.']);
        }

        if ($platform === 'shopify' && $conn->connection_method === PlatformConnection::CONNECTION_METHOD_WEBHOOK) {
            return response()->json([
                'ok'      => $conn->isWebhookVerified(),
                'message' => $conn->isWebhookVerified()
                    ? "Last webhook received {$conn->last_webhook_at?->diffForHumans()}."
                    : 'No verified webhook yet — send a test webhook from Shopify, then check again.',
            ]);
        }

        if ($platform === 'shopify' && $conn->connection_method === PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS) {
            return response()->json(app(ShopifyAuthService::class)->testConnection($conn));
        }

        if ($platform === 'shopify' && filled($conn->access_token)) {
            try {
                $ok = (new ShopifyConnector($conn))->authenticate();

                return response()->json(['ok' => $ok, 'message' => $ok ? 'Connected to Shopify.' : 'Shopify rejected the credentials.']);
            } catch (Throwable $e) {
                Log::warning('Shopify test connection failed', ['connection' => $conn->id, 'error' => $e->getMessage()]);

                return response()->json(['ok' => false, 'message' => 'Could not reach Shopify: ' . $e->getMessage()]);
            }
        }

        return response()->json([
            'ok'      => $conn->status === 'active',
            'message' => 'Connection record found.',
        ]);
    }

    /**
     * Real-API-truth Shopify capability diagnostics. Verified: connection
     * belongs to the active store, platform is shopify, no cross-store
     * access — same tenant-scoping pattern as every other action here
     * (store-scoped query, never a bare PlatformConnection::find()).
     */
    public function shopifyDiagnostics(Request $request, ShopifyCapabilityDiagnosticsService $diagnostics): \Illuminate\Http\JsonResponse
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return response()->json(['message' => 'No active store.'], 422);
        }

        $conn = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->where('platform', 'shopify')
            ->first();

        if ($conn === null) {
            return response()->json(['message' => 'No Shopify connection configured for this store.'], 422);
        }

        if ($conn->connection_method !== PlatformConnection::CONNECTION_METHOD_ADMIN_CLIENT_CREDENTIALS) {
            return response()->json(['message' => 'Diagnostics are only available for the Admin API via Client Credentials method.'], 422);
        }

        return response()->json($diagnostics->run($conn));
    }

    private function connectionFor(Request $request, string $platform): ?array
    {
        $store = $request->user()->getActiveStore();
        if ($store === null) return null;

        $conn = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->where('platform', $platform)
            ->first();

        return $conn?->only([
            'id', 'platform', 'status', 'label', 'api_url', 'shop_domain',
            'last_synced_at', 'synced_products_count', 'synced_orders_count',
        ]);
    }

    private function saveConnection(Request $request, string $platform, array $data): void
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $existing = PlatformConnection::query()->where('store_id', $store->id)->where('platform', $platform)->first();

        // Re-entering credentials invalidates any previous test/diagnostic
        // verdict — a stale error (or a stale "connected") must never
        // outlive the credentials it was measured against.
        PlatformConnection::updateOrCreate(
            ['store_id' => $store->id, 'platform' => $platform],
            array_merge($data, [
                'status' => 'active',
                'metadata' => collect($existing?->metadata ?? [])->except(['auth_check'])->all(),
            ]),
        );
    }
}
