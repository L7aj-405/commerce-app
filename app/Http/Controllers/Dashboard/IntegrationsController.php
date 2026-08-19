<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
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
        return Inertia::render('Dashboard/Integrations/Platforms/Shopify', [
            'connection' => $this->connectionFor($request, 'shopify'),
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
        $validated = $request->validate([
            'shop_domain'  => ['required', 'string'],
            'access_token' => ['required', 'string'],
        ]);
        $this->saveConnection($request, 'shopify', $validated);
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
        $conn = $this->connectionFor($request, $platform);
        return response()->json([
            'ok'      => $conn !== null && ($conn['status'] ?? null) === 'active',
            'message' => $conn ? 'Connection record found.' : 'No connection configured.',
        ]);
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

        PlatformConnection::updateOrCreate(
            ['store_id' => $store->id, 'platform' => $platform],
            array_merge($data, ['status' => 'active']),
        );
    }
}
