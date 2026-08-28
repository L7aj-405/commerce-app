<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Connectors\ShopifyConnector;
use App\Http\Controllers\Controller;
use App\Models\DeliveryConnection;
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

    /**
     * The Integrations Center — one page, three tabbed categories (commerce,
     * delivery, tools). NOT blanket-gated on integrations.manage (see the
     * route comment): a caller only gets the categories they actually hold
     * the permission for, and is 403'd only if they hold neither.
     */
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $store = $user->getActiveStore();

        $canCommerce = $user->hasStorePermission($store, 'integrations.manage');
        $canDelivery = $user->hasStorePermission($store, 'delivery.connections.manage');

        abort_unless($canCommerce || $canDelivery, 403, 'You do not have permission to access this area.');

        $platformByCode = $store === null ? collect() : PlatformConnection::query()
            ->where('store_id', $store->id)
            ->get(['id', 'platform', 'status', 'label', 'last_synced_at', 'synced_products_count', 'synced_orders_count'])
            ->keyBy('platform');

        // Legacy shape, still consumed by ChannelFrontendCoverageTest and any
        // other existing caller of the plain provider list — kept alongside
        // the new grouped shape rather than replaced.
        $providers = [
            ['key' => 'woocommerce', 'name' => 'WooCommerce', 'description' => 'WordPress-based stores'],
            ['key' => 'shopify',     'name' => 'Shopify',     'description' => 'Shopify storefronts'],
            ['key' => 'youcan',      'name' => 'YouCan',      'description' => 'YouCan Shop'],
            ['key' => 'whatsapp',    'name' => 'WhatsApp',    'description' => 'Order confirmations via WhatsApp'],
        ];

        $tab = $request->query('tab');
        $tab = in_array($tab, ['commerce', 'delivery', 'tools'], true) ? $tab : ($canCommerce ? 'commerce' : 'delivery');

        return Inertia::render('Dashboard/Integrations/Index', [
            'store'       => $store?->only(['id', 'name']),
            'providers'   => $providers,
            'connections' => $platformByCode->values(),
            'tab'         => $tab,
            'can'         => ['commerce' => $canCommerce, 'delivery' => $canDelivery],
            'commerce'    => $canCommerce ? $this->commerceCards($platformByCode) : [],
            'tools'       => $canCommerce ? $this->toolsCards($platformByCode) : [],
            'delivery'    => $canDelivery ? $this->deliveryCards($store) : [],
        ]);
    }

    /** @param \Illuminate\Support\Collection<string, PlatformConnection> $platformByCode */
    private function commerceCards($platformByCode): array
    {
        $defs = [
            ['code' => 'shopify',     'name' => 'Shopify',     'description' => 'Sync products, orders, and stock with your Shopify storefront.'],
            ['code' => 'woocommerce', 'name' => 'WooCommerce', 'description' => 'Sync products, orders, and stock with your WordPress-based WooCommerce store.'],
            ['code' => 'youcan',      'name' => 'YouCan',      'description' => 'Sync products, orders, and stock with your YouCan Shop.'],
        ];

        return array_map(function (array $d) use ($platformByCode) {
            /** @var PlatformConnection|null $conn */
            $conn = $platformByCode->get($d['code']);

            return [
                'code' => $d['code'],
                'category' => 'commerce',
                'name' => $d['name'],
                'description' => $d['description'],
                'status' => $this->platformCardStatus($conn),
                'capabilities' => ['Sync products', 'Sync orders', 'Stock sync'],
                'is_available' => true,
                'is_connected' => $conn?->status === 'active',
                'coming_soon' => false,
                'connect_url' => "/dashboard/integrations/{$d['code']}",
                'manage_url' => $conn !== null ? "/dashboard/integrations/connections/{$conn->id}" : null,
                'synced_products_count' => $conn?->synced_products_count,
                'synced_orders_count' => $conn?->synced_orders_count,
                'last_sync' => $conn?->last_synced_at?->toIso8601String(),
            ];
        }, $defs);
    }

    /** @param \Illuminate\Support\Collection<string, PlatformConnection> $platformByCode */
    private function toolsCards($platformByCode): array
    {
        $wa = $platformByCode->get('whatsapp');

        $cards = [[
            'code' => 'whatsapp',
            'category' => 'tools',
            'name' => 'WhatsApp',
            'description' => 'Send automated order confirmations to customers via WhatsApp.',
            'status' => $this->platformCardStatus($wa),
            'capabilities' => ['Order confirmations'],
            'is_available' => true,
            'is_connected' => $wa?->status === 'active',
            'coming_soon' => false,
            'connect_url' => '/dashboard/integrations/whatsapp',
            'manage_url' => $wa?->status === 'active' ? '/dashboard/integrations/whatsapp' : null,
        ]];

        foreach ([
            ['code' => 'google_sheets',    'name' => 'Google Sheets',    'description' => 'Export orders and stock data to a live spreadsheet.'],
            ['code' => 'barcode_scanner',  'name' => 'Barcode scanners', 'description' => 'Connect handheld or USB barcode scanners for faster picking and stock counts.'],
            ['code' => 'label_printer',    'name' => 'Label printers',   'description' => 'Print shipping labels and barcodes directly from the dashboard.'],
        ] as $d) {
            $cards[] = $this->comingSoonCard($d['code'], 'tools', $d['name'], $d['description']);
        }

        return $cards;
    }

    private function deliveryCards(?\App\Models\Store $store): array
    {
        $ozon = $store === null ? null : DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->first();

        $ozonStatus = match (true) {
            $ozon === null => 'not_connected',
            $ozon->status === DeliveryConnection::STATUS_CONNECTED => 'connected',
            $ozon->status === DeliveryConnection::STATUS_ERROR => 'error',
            default => 'not_connected', // STATUS_DISABLED
        };

        $sendit = $store === null ? null : DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->first();

        $senditStatus = match (true) {
            $sendit === null => 'not_connected',
            $sendit->status === DeliveryConnection::STATUS_CONNECTED => 'connected',
            $sendit->status === DeliveryConnection::STATUS_ERROR => 'error',
            default => 'not_connected', // STATUS_DISABLED
        };

        $cards = [
            [
                'code' => 'ozon',
                'category' => 'delivery',
                'name' => 'Ozon Express',
                'description' => 'Send packed orders, track shipments, and manage delivery notes.',
                'status' => $ozonStatus,
                'capabilities' => ['Create shipments', 'Tracking', 'City mapping', 'Delivery notes / BL'],
                'is_available' => true,
                'is_connected' => $ozonStatus === 'connected',
                'coming_soon' => false,
                'connect_url' => '/dashboard/delivery-connections',
                'manage_url' => '/dashboard/delivery-connections',
            ],
            [
                'code' => 'sendit',
                'category' => 'delivery',
                'name' => 'Sendit',
                'description' => 'Send packed orders, track shipments, and print labels.',
                'status' => $senditStatus,
                'capabilities' => ['Shipments', 'Tracking', 'District mapping', 'Labels', 'Webhooks'],
                'is_available' => true,
                'is_connected' => $senditStatus === 'connected',
                'coming_soon' => false,
                'connect_url' => '/dashboard/delivery-connections/sendit',
                'manage_url' => '/dashboard/delivery-connections/sendit',
            ],
        ];

        $cards[] = $this->comingSoonCard('amana', 'delivery', 'Amana', 'Moroccan delivery carrier.', ['Create shipments', 'Tracking']);

        return $cards;
    }

    private function comingSoonCard(string $code, string $category, string $name, string $description, array $capabilities = []): array
    {
        return [
            'code' => $code,
            'category' => $category,
            'name' => $name,
            'description' => $description,
            'status' => 'coming_soon',
            'capabilities' => $capabilities,
            'is_available' => false,
            'is_connected' => false,
            'coming_soon' => true,
            'connect_url' => null,
            'manage_url' => null,
        ];
    }

    /** Connected/Not connected/Error/Needs attention, from a nullable PlatformConnection. */
    private function platformCardStatus(?PlatformConnection $conn): string
    {
        if ($conn === null) return 'not_connected';
        if ($conn->status === 'active') return 'connected';
        if ($conn->status === 'error') return 'error';

        return 'needs_attention';
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
