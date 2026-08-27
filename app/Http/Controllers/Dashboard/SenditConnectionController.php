<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Connectors\Delivery\SenditConnector;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Services\Delivery\DeliveryCityMappingSuggestionService;
use App\Services\Delivery\SenditDistrictMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sendit connection settings, district sync, and district mapping — the
 * Sendit counterpart of DeliveryConnectionController (which stays Ozon-only
 * and unmodified). Same shape, same permission (delivery.connections.manage),
 * same underlying DeliveryProviderCity/CityDeliveryProviderMapping tables —
 * only provider_code differs.
 */
class SenditConnectionController extends Controller
{
    public function __construct(
        private readonly SenditDistrictMappingService $districts,
        private readonly DeliveryCityMappingSuggestionService $suggestions,
    ) {}

    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        $connection = $store === null ? null : DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->first();

        return Inertia::render('Dashboard/Delivery/SenditConnections', [
            'store' => $store?->only(['id', 'name']),
            'connection' => $connection?->toApiArray(),
            'unmapped_cities' => $store === null ? [] : $this->districts->unmappedCities($store)
                ->map(fn (City $c) => ['id' => $c->id, 'name' => $c->name, 'region' => $c->region])->values(),
            'mapped_cities' => $store === null ? [] : $this->mappedCities($store),
            'sendit_districts' => $store === null ? [] : DeliveryProviderCity::query()
                ->where('store_id', $store->id)
                ->where('provider_code', 'sendit')
                ->orderBy('city_name')
                ->get(['id', 'city_name', 'district_name', 'is_pickup_district']),
            // Distinct city count (as opposed to total district ROWS, which
            // can be several per city) — the number the UI actually cares
            // about for "did the sync really cover every major city".
            'sendit_distinct_cities_count' => $store === null ? 0 : DeliveryProviderCity::query()
                ->where('store_id', $store->id)
                ->where('provider_code', 'sendit')
                ->distinct()
                ->count('city_name'),
            'pickup_districts' => $store === null ? [] : DeliveryProviderCity::query()
                ->where('store_id', $store->id)
                ->where('provider_code', 'sendit')
                ->where('is_pickup_district', true)
                ->orderBy('city_name')
                ->get(['id', 'provider_city_id', 'city_name']),
            // Recomputed fresh on every load, like Ozon's — never persisted.
            'suggestions' => $store === null ? [] : $this->suggestions->suggestionsFor($store, 'sendit')->values(),
            // Sanity-check warning for the mapping UI — see
            // SenditDistrictMappingService::MAJOR_CITIES.
            'sendit_missing_major_cities' => $store === null ? [] : $this->districts->missingMajorCities($store),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $existing = DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->first();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:120'],
            // Required on first connect, optional on update — a blank value
            // means "keep the existing secret", never blanked out.
            'secret_key' => [$existing?->credential('secret_key') ? 'nullable' : 'required', 'string', 'max:255'],
            'default_pickup_district_id' => ['nullable', 'string'],
            'allow_open' => ['nullable', 'boolean'],
            'allow_try' => ['nullable', 'boolean'],
            'packaging_id' => ['nullable', 'string', 'max:60'],
            'option_exchange' => ['nullable', 'boolean'],
            'default_comment' => ['nullable', 'string', 'max:255'],
        ]);

        // No 'status' key on purpose — same rule as Ozon's storeOzon(): a
        // brand-new row gets the column's own 'disabled' default, and an
        // EXISTING connection's auth status is left exactly as it is.
        // Only test() (connected/error) and disconnect() (disabled) may
        // change status.
        DeliveryConnection::query()->updateOrCreate(
            ['store_id' => $store->id, 'provider_code' => 'sendit'],
            [
                'name' => $validated['name'],
                'credentials' => [
                    'public_key' => $validated['public_key'],
                    'secret_key' => filled($validated['secret_key'] ?? null)
                        ? $validated['secret_key']
                        : $existing?->credential('secret_key'),
                ],
                'settings' => [
                    'default_pickup_district_id' => $validated['default_pickup_district_id'] ?? null,
                    'allow_open' => $validated['allow_open'] ?? null,
                    'allow_try' => $validated['allow_try'] ?? null,
                    'packaging_id' => $validated['packaging_id'] ?? null,
                    'option_exchange' => $validated['option_exchange'] ?? null,
                    'default_comment' => $validated['default_comment'] ?? null,
                ],
                'created_by' => $request->user()->id,
            ],
        );

        return back()->with('success', 'Sendit credentials saved. Test the connection next.');
    }

    public function test(Request $request): RedirectResponse
    {
        $connection = $this->connectionFor($request);

        $connector = new SenditConnector($connection);
        $result = $connector->testConnection();

        $connection->update([
            'status' => $result['ok'] ? DeliveryConnection::STATUS_CONNECTED : DeliveryConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => $result['ok'] ? null : $result['message'],
        ]);

        return $result['ok']
            ? back()->with('success', 'Sendit connection is working.')
            : back()->with('error', $result['message']);
    }

    /** JSON, not a redirect — same reasoning as Ozon's syncCities(): the button reads the response body directly. */
    public function syncDistricts(Request $request): JsonResponse
    {
        $connection = $this->connectionFor($request);

        try {
            $counts = $this->districts->syncDistricts($connection);
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first();
            $connection->update(['last_city_sync_error' => $message]);

            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        if ($counts['total_count'] === 0) {
            $message = 'District sync failed: no Sendit districts were imported.';
            $connection->update(['last_city_sync_error' => $message]);

            return response()->json(['ok' => false, 'message' => $message, ...$counts], 422);
        }

        $connection->update([
            'last_city_sync_at' => now(),
            'last_city_sync_error' => null,
            'last_city_sync_count' => $counts['total_count'],
            'last_city_sync_pickup_district_id' => $counts['pickup_district_used'],
            'last_city_sync_page_count' => $counts['pages_fetched'],
        ]);

        $pickupLabel = $this->pickupDistrictLabel($connection, $counts['pickup_district_used']);
        $pages = $counts['pages_fetched'];

        return response()->json([
            'ok' => true,
            'message' => "Synced {$counts['total_count']} Sendit districts across {$pages} page" . ($pages === 1 ? '' : 's')
                . " ({$counts['imported_count']} new, {$counts['updated_count']} updated) — {$counts['distinct_cities_count']} cities found."
                . " Pickup district used: {$pickupLabel}.",
            ...$counts,
        ]);
    }

    /** "Casablanca (default 46)" if the id is the documented Sendit default and unresolvable, else the district's own synced name, else just the raw id. */
    private function pickupDistrictLabel(DeliveryConnection $connection, string $pickupDistrictId): string
    {
        $name = DeliveryProviderCity::query()
            ->where('store_id', $connection->store_id)
            ->where('provider_code', 'sendit')
            ->where('provider_city_id', $pickupDistrictId)
            ->value('city_name');

        if ($name !== null) {
            return "{$name} (id {$pickupDistrictId})";
        }

        return $pickupDistrictId === \App\Connectors\Delivery\SenditConnector::DEFAULT_PICKUP_DISTRICT_ID
            ? "Casablanca / id {$pickupDistrictId} (Sendit default)"
            : "id {$pickupDistrictId}";
    }

    public function disconnect(Request $request): JsonResponse
    {
        $connection = $this->connectionFor($request);
        $connection->update(['status' => DeliveryConnection::STATUS_DISABLED]);

        return response()->json(['ok' => true, 'message' => 'Sendit disconnected.']);
    }

    public function mapCity(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate([
            'city_id' => ['required', 'string'],
            'delivery_provider_city_id' => ['required', 'string'],
        ]);

        $city = City::findOrFail($validated['city_id']);
        $providerCity = DeliveryProviderCity::where('store_id', $store->id)->findOrFail($validated['delivery_provider_city_id']);

        $this->districts->mapCity($store, $city, $providerCity);

        return back()->with('success', "Mapped {$city->name} to {$providerCity->city_name}.");
    }

    public function mapAllSuggested(Request $request): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate(['overwrite' => ['nullable', 'boolean']]);

        $result = $this->districts->mapAllSuggested($store, $this->suggestions, (bool) ($validated['overwrite'] ?? false));

        return response()->json([
            'ok' => true,
            'message' => "Mapped {$result['mapped_count']} cities" . ($result['skipped_count'] > 0
                ? ", skipped {$result['skipped_count']} needing review."
                : '.'),
            ...$result,
        ]);
    }

    public function clearMapping(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate(['city_id' => ['required', 'string']]);

        $deleted = CityDeliveryProviderMapping::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->where('city_id', $validated['city_id'])
            ->delete();

        return $deleted > 0
            ? back()->with('success', 'Mapping cleared.')
            : back()->with('error', 'No mapping to clear.');
    }

    /** POST /deliveries/getlabels for one or more already-sent Sendit codes. */
    public function getLabels(Request $request): JsonResponse
    {
        $connection = $this->connectionFor($request);

        $validated = $request->validate([
            'codes' => ['required', 'array', 'min:1'],
            'codes.*' => ['string'],
            'print_format' => ['nullable', 'integer'],
        ]);

        $connector = new SenditConnector($connection);
        $result = $connector->getLabels($validated['codes'], (int) ($validated['print_format'] ?? 1));

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => $result['error'] ?? 'Could not fetch labels.'], 422);
        }

        $fileUrl = $this->extractLabelFileUrl($result['raw']);

        return response()->json(['ok' => true, 'file_url' => $fileUrl, 'raw' => $result['raw']]);
    }

    private function extractLabelFileUrl(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $url = $body['data']['fileUrl'] ?? $body['fileUrl'] ?? $body['data']['file_url'] ?? $body['file_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function connectionFor(Request $request): DeliveryConnection
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $connection = DeliveryConnection::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
            ->first();

        abort_if($connection === null, 404, 'No Sendit connection configured.');

        return $connection;
    }

    /** @return array<int, array<string, mixed>> */
    private function mappedCities(\App\Models\Store $store): array
    {
        return CityDeliveryProviderMapping::query()
            ->where('store_id', $store->id)
            ->where('provider_code', 'sendit')
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
