<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Connectors\Delivery\OzonExpressConnector;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Services\Delivery\DeliveryCityMappingSuggestionService;
use App\Services\Delivery\OzonCityMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Delivery provider connection settings — Ozon Express first. */
class DeliveryConnectionController extends Controller
{
    public function __construct(
        private readonly OzonCityMappingService $cityMapping,
        private readonly DeliveryCityMappingSuggestionService $suggestions,
    ) {}

    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        $connection = $store === null ? null : DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->first();

        return Inertia::render('Dashboard/Delivery/Connections', [
            'store' => $store?->only(['id', 'name']),
            'connection' => $connection?->toApiArray(),
            'unmapped_cities' => $store === null ? [] : $this->cityMapping->unmappedCities($store)
                ->map(fn (City $c) => ['id' => $c->id, 'name' => $c->name, 'region' => $c->region])->values(),
            'mapped_cities' => $store === null ? [] : $this->mappedCities($store),
            'ozon_cities' => $store === null ? [] : DeliveryProviderCity::query()
                ->where('store_id', $store->id)
                ->where('provider_code', 'ozon')
                ->orderBy('city_name')
                ->get(['id', 'city_name']),
            // Suggestions for every still-unmapped city — recomputed fresh on
            // every load/reload, never persisted, so a fresh "Sync cities"
            // (or mapping a row) is reflected immediately with no separate
            // "refresh" step required server-side.
            'suggestions' => $store === null ? [] : $this->suggestions->suggestionsFor($store)->values(),
        ]);
    }

    public function storeOzon(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => ['required', 'string', 'max:60'],
            'api_key' => ['required', 'string', 'max:120'],
            // Ozon's documented values: parcel-stock is "1" (stock) or "0"
            // (ramassage); parcel-open is "1" (ouvrir) or "2" (ne pas
            // ouvrir) — NOT a boolean. fragile/replace stay real booleans.
            'default_parcel_stock' => ['nullable', 'in:0,1'],
            'default_parcel_open' => ['nullable', 'in:1,2'],
            'default_fragile' => ['nullable', 'boolean'],
            'default_replace' => ['nullable', 'boolean'],
            'default_parcel_nature' => ['nullable', 'string', 'max:120'],
            'default_note' => ['nullable', 'string', 'max:255'],
        ]);

        // No 'status' key here on purpose: a brand-new row gets the column's
        // own 'disabled' default (untested), and an EXISTING connection's
        // auth status is left exactly as it is — saving settings/credentials
        // must never silently flip an already-connected/error connection
        // back to disabled. Only test() (connected/error) and disconnect()
        // (disabled) may change status.
        DeliveryConnection::query()->updateOrCreate(
            ['store_id' => $store->id, 'provider_code' => 'ozon'],
            [
                'name' => $validated['name'],
                'credentials' => [
                    'customer_id' => $validated['customer_id'],
                    'api_key' => $validated['api_key'],
                ],
                'settings' => [
                    'default_parcel_stock' => $validated['default_parcel_stock'] ?? null,
                    'default_parcel_open' => $validated['default_parcel_open'] ?? null,
                    'default_fragile' => $validated['default_fragile'] ?? null,
                    'default_replace' => $validated['default_replace'] ?? null,
                    'default_parcel_nature' => $validated['default_parcel_nature'] ?? null,
                    'default_note' => $validated['default_note'] ?? null,
                ],
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Ozon Express credentials saved. Test the connection next.');
    }

    public function test(Request $request, DeliveryConnection $connection): RedirectResponse
    {
        $this->authorizeConnection($request, $connection);

        $connector = new OzonExpressConnector($connection);
        $result = $connector->testConnection();

        $connection->update([
            'status' => $result['ok'] ? DeliveryConnection::STATUS_CONNECTED : DeliveryConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
        ]);

        return $result['ok']
            ? back()->with('success', 'Ozon Express connection is working.')
            : back()->with('error', $result['message']);
    }

    /**
     * JSON, not a redirect: the "Sync cities" button calls this via axios and
     * reads the response body directly (see Connections.jsx's `run()`) — a
     * redirect resolves to the followed page's HTML, which silently hides
     * both the real outcome and any error behind a generic "Done." message.
     *
     * IMPORTANT: this never touches `status` or `last_error` (the
     * authentication fields — see test()/disconnect()). A city-sync problem
     * is recorded exclusively in `last_city_sync_*`, so it can never disable
     * or otherwise misrepresent an already-authenticated connection.
     */
    public function syncCities(Request $request, DeliveryConnection $connection): JsonResponse
    {
        $this->authorizeConnection($request, $connection);

        try {
            $counts = $this->cityMapping->syncCities($connection);
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first();
            $connection->update(['last_city_sync_error' => $message]);

            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        if ($counts['total_count'] === 0) {
            $message = 'City sync failed: no Ozon cities were imported.';
            $connection->update(['last_city_sync_error' => $message]);

            return response()->json(['ok' => false, 'message' => $message, ...$counts], 422);
        }

        $connection->update([
            'last_city_sync_at' => now(),
            'last_city_sync_error' => null,
            'last_city_sync_count' => $counts['total_count'],
        ]);

        return response()->json([
            'ok' => true,
            'message' => "Synced {$counts['total_count']} Ozon cities ({$counts['imported_count']} new, {$counts['updated_count']} updated).",
            ...$counts,
        ]);
    }

    /**
     * The only action that turns an existing connection off (status ->
     * disabled). JSON for the same reason as syncCities() — the "Disconnect"
     * button also runs through the axios-based `run()` helper.
     */
    public function disconnect(Request $request, DeliveryConnection $connection): JsonResponse
    {
        $this->authorizeConnection($request, $connection);

        $connection->update(['status' => DeliveryConnection::STATUS_DISABLED]);

        return response()->json(['ok' => true, 'message' => 'Ozon Express disconnected.']);
    }

    public function mapCity(Request $request, DeliveryConnection $connection): RedirectResponse
    {
        $store = $this->authorizeConnection($request, $connection);

        $validated = $request->validate([
            'city_id' => ['required', 'string'],
            'delivery_provider_city_id' => ['required', 'string'],
        ]);

        $city = City::findOrFail($validated['city_id']);
        $providerCity = DeliveryProviderCity::where('store_id', $store->id)->findOrFail($validated['delivery_provider_city_id']);

        $this->cityMapping->mapCity($store, $city, $providerCity);

        return back()->with('success', "Mapped {$city->name} to {$providerCity->city_name}.");
    }

    /** Bulk-applies every safe (can_auto_map) suggestion; everything else is left for manual review. */
    public function mapAllSuggested(Request $request, DeliveryConnection $connection): JsonResponse
    {
        $store = $this->authorizeConnection($request, $connection);

        $validated = $request->validate(['overwrite' => ['nullable', 'boolean']]);

        $result = $this->cityMapping->mapAllSuggested($store, $this->suggestions, (bool) ($validated['overwrite'] ?? false));

        return response()->json([
            'ok' => true,
            'message' => "Mapped {$result['mapped_count']} cities" . ($result['skipped_count'] > 0
                ? ", skipped {$result['skipped_count']} needing review."
                : '.'),
            ...$result,
        ]);
    }

    public function clearMapping(Request $request, DeliveryConnection $connection): RedirectResponse
    {
        $store = $this->authorizeConnection($request, $connection);

        $validated = $request->validate(['city_id' => ['required', 'string']]);

        $deleted = CityDeliveryProviderMapping::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->where('city_id', $validated['city_id'])
            ->delete();

        return $deleted > 0
            ? back()->with('success', 'Mapping cleared.')
            : back()->with('error', 'No mapping to clear.');
    }

    private function authorizeConnection(Request $request, DeliveryConnection $connection): \App\Models\Store
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $connection->store_id !== $store->id, 404);

        return $store;
    }

    /** @return array<int, array<string, mixed>> */
    private function mappedCities(\App\Models\Store $store): array
    {
        return \App\Models\CityDeliveryProviderMapping::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'ozon')
            ->with(['city:id,name', 'providerCity:id,city_name'])
            ->get()
            ->map(fn ($m) => [
                'city_id' => $m->city_id,
                'city_name' => $m->city?->name,
                'provider_city_id' => $m->delivery_provider_city_id,
                'provider_city_name' => $m->providerCity?->city_name,
            ])
            ->values()
            ->all();
    }
}
