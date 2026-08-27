<?php

declare(strict_types=1);

namespace App\Connectors\Delivery;

use App\Contracts\DeliveryProviderConnectorInterface;
use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Shipment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sendit (https://app.sendit.ma/api/v1). Token auth: POST /login with
 * public_key/secret_key returns a bearer token, sent as
 * `Authorization: Bearer {token}` on every subsequent call. The token is
 * cached per-connection (safe: it's not the credential itself, and the
 * cache key is scoped to this connection's id) so a burst of calls in one
 * request doesn't re-authenticate every time; a 401 on any authenticated
 * call clears the cached token and retries once with a fresh one.
 *
 * Sendit has no documented "delivery note" (Bon de Livraison) concept —
 * Phase 1 scope excludes it, so those four interface methods are
 * unsupported no-ops here (never called from the UI for a Sendit
 * connection; see DeliveryConnectorFactory/IntegrationsController).
 */
class SenditConnector implements DeliveryProviderConnectorInterface
{
    private const BASE_URL = 'https://app.sendit.ma/api/v1';

    private const TOKEN_TTL_SECONDS = 3600;

    /**
     * Sendit's documented default pickup district (Casablanca) — used only
     * as a last-resort fallback when the connection has no
     * `default_pickup_district_id` configured yet. Never hardcode
     * "Casablanca" anywhere else; this id is the one source of truth.
     */
    public const DEFAULT_PICKUP_DISTRICT_ID = '46';

    /**
     * Hard ceiling on how many /districts (or /districts/pickup-cities)
     * pages a single sync will ever fetch — a safety net against a buggy or
     * malicious paginator response looping forever, not a realistic limit
     * (Sendit's whole district catalogue is nowhere near 50 * per_page rows).
     */
    private const MAX_PAGES = 50;

    public function __construct(private readonly DeliveryConnection $connection)
    {
        if ($connection->provider_code !== 'sendit') {
            throw new \InvalidArgumentException(
                "SenditConnector requires provider_code 'sendit', got '{$connection->provider_code}'"
            );
        }
    }

    private function publicKey(): string
    {
        return (string) ($this->connection->credential('public_key') ?? '');
    }

    private function secretKey(): string
    {
        return (string) ($this->connection->credential('secret_key') ?? '');
    }

    private function tokenCacheKey(): string
    {
        return "sendit:token:{$this->connection->id}";
    }

    private function httpClient(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->connectTimeout(10)
            ->timeout(30)
            ->acceptJson();
    }

    /**
     * POST /login -> {token}. Never logs public_key/secret_key/token —
     * only the connection id and outcome.
     *
     * @return array{ok: bool, token?: string, message?: string, raw?: mixed}
     */
    public function authenticate(): array
    {
        try {
            $response = $this->httpClient()->asJson()->post('/login', [
                'public_key' => $this->publicKey(),
                'secret_key' => $this->secretKey(),
            ]);

            $body = $this->safeJson($response);

            if ($response->failed()) {
                Log::warning('Sendit authentication failed', [
                    'connection_id' => $this->connection->id,
                    'status' => $response->status(),
                ]);

                return ['ok' => false, 'message' => $this->extractError($body) ?? "Sendit login returned HTTP {$response->status()}.", 'raw' => $body];
            }

            $token = $this->extractToken($body);

            if ($token === null) {
                return ['ok' => false, 'message' => 'Sendit login did not return a token.', 'raw' => $body];
            }

            Cache::put($this->tokenCacheKey(), $token, self::TOKEN_TTL_SECONDS);

            return ['ok' => true, 'token' => $token, 'raw' => $body];
        } catch (Throwable $e) {
            Log::warning('Sendit authentication request failed', [
                'connection_id' => $this->connection->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => "Could not reach Sendit: {$e->getMessage()}"];
        }
    }

    private function extractToken(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $token = $body['token'] ?? $body['data']['token'] ?? $body['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** Cached token if present, else authenticates once and caches the result. Never logged. */
    private function token(): ?string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $result = $this->authenticate();

        return $result['ok'] ? $result['token'] : null;
    }

    /**
     * An authenticated client for one request. `$retried` guards the
     * single 401-triggered re-authentication (never loop endlessly if
     * credentials are simply wrong).
     */
    private function authedClient(): ?PendingRequest
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        return $this->httpClient()->withToken($token);
    }

    /** @return array{ok: bool, raw: mixed, error?: string} one retry on 401 with a freshly-fetched token. */
    private function authedRequest(string $method, string $endpoint, array $payload = []): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $client = $this->authedClient();

            if ($client === null) {
                return ['ok' => false, 'raw' => null, 'error' => 'Could not authenticate with Sendit.'];
            }

            try {
                $response = $method === 'GET'
                    ? $client->get($endpoint, $payload)
                    : $client->asJson()->post($endpoint, $payload);
            } catch (Throwable $e) {
                Log::warning('Sendit request failed', ['connection_id' => $this->connection->id, 'endpoint' => $endpoint, 'error' => $e->getMessage()]);

                return ['ok' => false, 'raw' => null, 'error' => 'Could not reach Sendit.'];
            }

            if ($response->status() === 401 && $attempt === 0) {
                // Token expired/invalid — clear and retry once with a fresh one.
                Cache::forget($this->tokenCacheKey());

                continue;
            }

            $body = $this->safeJson($response);

            if ($response->failed()) {
                return ['ok' => false, 'raw' => $body, 'error' => $this->extractError($body) ?? "Sendit returned HTTP {$response->status()}."];
            }

            // Sendit documents a `success` flag on business responses —
            // HTTP 200 with success=false is still a rejection.
            if (is_array($body) && array_key_exists('success', $body) && $body['success'] === false) {
                return ['ok' => false, 'raw' => $body, 'error' => $this->extractError($body) ?? 'Sendit rejected the request.'];
            }

            return ['ok' => true, 'raw' => $body];
        }

        return ['ok' => false, 'raw' => null, 'error' => 'Sendit rejected the authentication token.'];
    }

    /** @return array{ok: bool, message: string, raw?: mixed} */
    public function testConnection(): array
    {
        Cache::forget($this->tokenCacheKey());
        $result = $this->authenticate();

        return [
            'ok' => $result['ok'],
            'message' => $result['ok'] ? 'Connected.' : ($result['message'] ?? 'Could not authenticate with Sendit.'),
            'raw' => $result['raw'] ?? null,
        ];
    }

    /**
     * GET /districts, fully paginated. Sendit's own vocabulary (ville/name/
     * arabic_name/price/delais/pickup_district) is mapped into the shared
     * DeliveryProviderCity shape the resolver/mapping UI already understand
     * — `city_name` uses `ville` (Sendit's documented city field);
     * `district_name` keeps `name` (the district WITHIN that city) as its
     * own field rather than falling back into `city_name` and losing
     * `ville`, since many districts share one `ville`.
     *
     * `pickup-district` is a required-by-Sendit query param that affects
     * the price/delais returned per row (different pickup origins price
     * differently) — the caller resolves which one to use (the connection's
     * configured default, or DEFAULT_PICKUP_DISTRICT_ID as a last resort);
     * this connector never guesses on its own.
     *
     * @return array{ok: bool, cities: array<int, array{
     *     provider_city_id: string, city_name: string, district_name: ?string,
     *     name_arabic: ?string, price: ?float, delais: ?string,
     *     is_pickup_district: ?bool, raw: mixed
     * }>, pages_fetched: int, total_reported: ?int, pickup_district_used: string, error?: string}
     */
    public function listDistricts(?string $pickupDistrictId = null): array
    {
        $pickupDistrictId = filled($pickupDistrictId) ? $pickupDistrictId : self::DEFAULT_PICKUP_DISTRICT_ID;

        $fetch = $this->fetchAllPages('/districts', ['pickup-district' => $pickupDistrictId]);

        if (! $fetch['ok']) {
            return [
                'ok' => false, 'cities' => [],
                'pages_fetched' => $fetch['pages_fetched'], 'total_reported' => $fetch['total_reported'],
                'pickup_district_used' => $pickupDistrictId,
                'error' => $fetch['error'] ?? 'Could not fetch Sendit districts.',
            ];
        }

        $districts = [];

        foreach ($fetch['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? $row['district_id'] ?? null;
            $ville = $this->stringOrNull($row['ville'] ?? null);
            $name = $this->stringOrNull($row['name'] ?? null);
            $cityName = $ville ?? $name;

            if ($id === null || $cityName === null) {
                continue;
            }

            $districts[] = [
                'provider_city_id' => (string) $id,
                'city_name' => $cityName,
                // Only set district_name when it's a genuinely distinct
                // label from city_name — never duplicate the same string
                // into both fields when a row has no separate `ville`.
                'district_name' => ($name !== null && $name !== $cityName) ? $name : null,
                'name_arabic' => $this->stringOrNull($row['name_arabic'] ?? $row['arabic_name'] ?? null),
                'price' => $this->floatOrNull($row['price'] ?? null),
                'delais' => $this->stringOrNull($row['delais'] ?? null),
                'is_pickup_district' => array_key_exists('pickup_district', $row) ? (bool) $row['pickup_district'] : null,
                'raw' => $row,
            ];
        }

        return [
            'ok' => true, 'cities' => $districts,
            'pages_fetched' => $fetch['pages_fetched'], 'total_reported' => $fetch['total_reported'],
            'pickup_district_used' => $pickupDistrictId,
        ];
    }

    /**
     * GET /districts/pickup-cities, fully paginated — the subset of
     * districts valid as a pickup point, used ONLY to populate the "Default
     * pickup district" dropdown. Deliberately a SEPARATE call from
     * listDistricts() (never derived from delivery-district rows) per the
     * documented distinction between a delivery destination and a pickup
     * origin.
     *
     * @return array{ok: bool, cities: array<int, array<string, mixed>>, pages_fetched: int, error?: string}
     */
    public function listPickupCities(): array
    {
        $fetch = $this->fetchAllPages('/districts/pickup-cities');

        if (! $fetch['ok']) {
            return ['ok' => false, 'cities' => [], 'pages_fetched' => $fetch['pages_fetched'], 'error' => $fetch['error'] ?? 'Could not fetch Sendit pickup districts.'];
        }

        $districts = [];

        foreach ($fetch['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? $row['district_id'] ?? null;
            $name = $row['ville'] ?? $row['name'] ?? null;

            if ($id === null || $name === null) {
                continue;
            }

            $districts[] = ['provider_city_id' => (string) $id, 'city_name' => (string) $name, 'raw' => $row];
        }

        return ['ok' => true, 'cities' => $districts, 'pages_fetched' => $fetch['pages_fetched']];
    }

    /** @deprecated interface compatibility alias for listDistricts() — a "city" here IS a district. */
    public function listCities(): array
    {
        return $this->listDistricts();
    }

    /**
     * Fetches every page of a Laravel-style paginated Sendit endpoint,
     * merging each page's rows. Stops when the reported current_page
     * reaches last_page, when next_page_url is null/absent (a response
     * with no pagination metadata at all — e.g. a genuinely single-page
     * dataset — falls into this case and correctly stops after page 1), or
     * when MAX_PAGES is hit as a last-resort safety net.
     *
     * @param  array<string, mixed>  $baseQuery  merged with `page` on every request
     * @return array{ok: bool, rows: array<int, mixed>, pages_fetched: int, total_reported: ?int, error?: string}
     */
    private function fetchAllPages(string $endpoint, array $baseQuery = []): array
    {
        $rows = [];
        $page = 1;
        $pagesFetched = 0;
        $totalReported = null;

        do {
            $result = $this->authedRequest('GET', $endpoint, array_merge($baseQuery, ['page' => $page]));

            if (! $result['ok']) {
                // A later page failing after earlier pages already
                // succeeded is still a genuine incomplete-sync error — the
                // caller must not silently treat a partial fetch as
                // complete, so this is never reported as ok:true.
                return [
                    'ok' => false, 'rows' => $rows, 'pages_fetched' => $pagesFetched,
                    'total_reported' => $totalReported, 'error' => $result['error'] ?? 'Could not fetch all pages.',
                ];
            }

            $body = $result['raw'];
            $rows = array_merge($rows, $this->extractRows($body));
            $pagesFetched++;

            $meta = $this->extractPaginationMeta($body);
            $totalReported = $meta['total'] ?? $totalReported;
            $currentPage = $meta['current_page'] ?? $page;
            $lastPage = $meta['last_page'] ?? null;
            $nextPageUrl = $meta['next_page_url'] ?? null;

            $hasMore = $lastPage !== null ? $currentPage < $lastPage : $nextPageUrl !== null;
            $page = $currentPage + 1;
        } while ($hasMore && $pagesFetched < self::MAX_PAGES);

        return ['ok' => true, 'rows' => $rows, 'pages_fetched' => $pagesFetched, 'total_reported' => $totalReported];
    }

    /**
     * Laravel's default flat paginator shape puts current_page/last_page/
     * next_page_url/total alongside `data` at the TOP level of the
     * response. Also checked one level under `data` defensively, in case a
     * response ever nests the paginator object there instead — never
     * assumed, always read defensively (matches this class's existing
     * pattern for tolerating response-shape variance).
     *
     * @return array{total?: ?int, per_page?: mixed, current_page?: ?int, last_page?: ?int, next_page_url?: mixed, prev_page_url?: mixed}
     */
    private function extractPaginationMeta(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $candidates = [$body];

        if (isset($body['data']) && is_array($body['data']) && ! array_is_list($body['data'])) {
            $candidates[] = $body['data'];
        }

        foreach ($candidates as $candidate) {
            if (isset($candidate['last_page']) || isset($candidate['next_page_url']) || isset($candidate['current_page'])) {
                return [
                    'total' => isset($candidate['total']) ? (int) $candidate['total'] : null,
                    'per_page' => $candidate['per_page'] ?? null,
                    'current_page' => isset($candidate['current_page']) ? (int) $candidate['current_page'] : null,
                    'last_page' => isset($candidate['last_page']) ? (int) $candidate['last_page'] : null,
                    'next_page_url' => $candidate['next_page_url'] ?? null,
                    'prev_page_url' => $candidate['prev_page_url'] ?? null,
                ];
            }
        }

        return [];
    }

    private function extractRows(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['data', 'districts', 'cities'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                // A paginator object nested under this key (associative,
                // e.g. {"data": {"current_page":1,"data":[...],...}}) is
                // never a row list itself — descend into ITS "data" instead
                // of returning the paginator's own keys as if they were rows.
                if (! array_is_list($body[$key]) && isset($body[$key]['data']) && is_array($body[$key]['data'])) {
                    return array_values($body[$key]['data']);
                }

                return array_values($body[$key]);
            }
        }

        return array_is_list($body) ? $body : [];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * $options must already carry the resolved fields — city/district
     * mapping and pickup-district defaults are the caller's job (see
     * SenditShipmentService), this connector performs no lookups:
     * pickup_district_id, district_id, name, phone, address, amount,
     * comment?, reference, allow_open, allow_try, products_from_stock,
     * products?, packaging_id?, option_exchange, delivery_exchange_id?
     *
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, tracking_number?: string, provider_shipment_id?: string, raw: mixed, error?: string, debug?: array<string, mixed>}
     */
    public function createShipment(Order|PosOrder $order, DeliveryConnection $connection, array $options = []): array
    {
        $payload = array_filter([
            'pickup_district_id' => $options['pickup_district_id'] ?? null,
            'district_id' => $options['district_id'] ?? null,
            'name' => $options['name'] ?? null,
            'amount' => $options['amount'] ?? null,
            'address' => $options['address'] ?? null,
            'phone' => $options['phone'] ?? null,
            'comment' => $options['comment'] ?? null,
            'reference' => $options['reference'] ?? null,
            'allow_open' => $options['allow_open'] ?? null,
            'allow_try' => $options['allow_try'] ?? null,
            'products_from_stock' => $options['products_from_stock'] ?? 0,
            'products' => $options['products'] ?? null,
            'packaging_id' => $options['packaging_id'] ?? null,
            'option_exchange' => $options['option_exchange'] ?? 0,
            'delivery_exchange_id' => $options['delivery_exchange_id'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $result = $this->authedRequest('POST', '/deliveries', $payload);

        if (! $result['ok']) {
            return [
                'ok' => false,
                'raw' => $result['raw'],
                'error' => $result['error'] ?? 'Sendit did not accept this delivery.',
                'debug' => $this->debugInfo($payload, $result['raw']),
            ];
        }

        $code = $this->extractCode($result['raw']);

        if ($code === null) {
            return [
                'ok' => false,
                'raw' => $result['raw'],
                'error' => 'Sendit response did not include a delivery code.',
                'debug' => $this->debugInfo($payload, $result['raw']),
            ];
        }

        return [
            'ok' => true,
            'tracking_number' => $code,
            'provider_shipment_id' => $code,
            'raw' => $result['raw'],
        ];
    }

    private function extractCode(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $code = $body['data']['code'] ?? $body['code'] ?? null;

        return is_string($code) || is_int($code) ? (string) $code : null;
    }

    /**
     * Everything needed to answer "what did we send, and what did Sendit
     * say back" — never the public_key/secret_key/token (none of those are
     * ever part of $payload or the response body).
     *
     * @param  array<string, mixed>  $payload
     * @return array{sent_district_id: ?string, sent_pickup_district_id: ?string, sent_amount: mixed, has_required_fields: bool, response_keys: array<int, string>}
     */
    private function debugInfo(array $payload, mixed $body): array
    {
        return [
            'sent_district_id' => $payload['district_id'] ?? null,
            'sent_pickup_district_id' => $payload['pickup_district_id'] ?? null,
            'sent_amount' => $payload['amount'] ?? null,
            'has_required_fields' => filled($payload['name'] ?? null)
                && filled($payload['phone'] ?? null)
                && filled($payload['address'] ?? null)
                && filled($payload['district_id'] ?? null)
                && filled($payload['pickup_district_id'] ?? null),
            'response_keys' => is_array($body) ? array_keys($body) : [],
        ];
    }

    /** @return array{ok: bool, raw: mixed, error?: string} */
    public function getShipmentInfo(Shipment $shipment): array
    {
        return $this->authedRequest('GET', "/deliveries/{$shipment->tracking_number}");
    }

    /** @return array{ok: bool, provider_status?: string, normalized_status?: string, raw: mixed, error?: string} */
    public function trackShipment(Shipment $shipment): array
    {
        $result = $this->authedRequest('GET', "/deliveries/{$shipment->tracking_number}");

        if (! $result['ok']) {
            return $result;
        }

        $data = is_array($result['raw']) ? ($result['raw']['data'] ?? $result['raw']) : null;
        $status = is_array($data) ? ($data['status'] ?? null) : null;

        if ($status === null) {
            return ['ok' => false, 'raw' => $result['raw'], 'error' => 'Sendit delivery response had no status.'];
        }

        return [
            'ok' => true,
            'provider_status' => (string) $status,
            'normalized_status' => $this->normalizeStatus((string) $status),
            'raw' => $result['raw'],
        ];
    }

    /**
     * Sendit's docs show only a single-delivery GET /deliveries/{code} — no
     * documented bulk endpoint, so this loops the single-shipment call.
     * Phase 1 scope; revisit if Sendit later documents a bulk tracking route.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return array<string, array{ok: bool, provider_status?: string, normalized_status?: string, raw: mixed, error?: string}>
     */
    public function trackShipmentsBulk(Collection $shipments): array
    {
        $results = [];

        foreach ($shipments as $shipment) {
            if (blank($shipment->tracking_number)) {
                continue;
            }

            $results[$shipment->tracking_number] = $this->trackShipment($shipment);
        }

        return $results;
    }

    /**
     * POST /deliveries/getlabels. Body: {codesToPrint: "CODE1,CODE2",
     * printFormat}. Returns the raw response — the caller (SenditShipmentService)
     * pulls out a safe file URL, if present, to store/show.
     *
     * @param  array<int, string>  $codes
     * @return array{ok: bool, raw: mixed, error?: string}
     */
    public function getLabels(array $codes, int $printFormat = 1): array
    {
        if ($codes === []) {
            return ['ok' => false, 'raw' => null, 'error' => 'No delivery codes to print.'];
        }

        return $this->authedRequest('POST', '/deliveries/getlabels', [
            'codesToPrint' => implode(',', $codes),
            'printFormat' => $printFormat,
        ]);
    }

    /** GET /all-status-deliveries — Sendit's documented status vocabulary, for diagnostics/UI only (normalizeStatus() is the source of truth for mapping). @return array{ok: bool, raw: mixed, error?: string} */
    public function getStatuses(): array
    {
        return $this->authedRequest('GET', '/all-status-deliveries');
    }

    /**
     * HMAC-SHA256 over the raw webhook body, hex-encoded (PHP's
     * hash_hmac() default), constant-time compared — Sendit's docs specify
     * the algorithm but not the encoding; hex is the more common default
     * for a bare "HMAC-SHA256" spec (vs. Shopify/WooCommerce's explicitly
     * base64 HMAC). If Sendit's real webhooks turn out to be base64, this
     * is the one place to change.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, ?string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === null || $secret === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $signature);
    }

    public function normalizeStatus(string $providerStatus): string
    {
        return SenditStatusMapper::normalize($providerStatus);
    }

    // -- DeliveryProviderConnectorInterface: delivery-note methods --------
    // Sendit has no documented Bon-de-Livraison concept; Phase 1 scope
    // explicitly excludes it. These exist only to satisfy the shared
    // interface and are never called from the Sendit UI/controllers.

    public function createDeliveryNote(DeliveryConnection $connection, string $ref): array
    {
        return ['ok' => false, 'raw' => null, 'error' => 'Sendit does not support delivery notes in this phase.'];
    }

    public function addParcelsToDeliveryNote(DeliveryConnection $connection, string $ref, array $trackingNumbers): array
    {
        return ['ok' => false, 'raw' => null, 'error' => 'Sendit does not support delivery notes in this phase.'];
    }

    public function saveDeliveryNote(DeliveryConnection $connection, string $ref): array
    {
        return ['ok' => false, 'raw' => null, 'error' => 'Sendit does not support delivery notes in this phase.'];
    }

    public function getDeliveryNotePdfUrls(string $ref): array
    {
        return ['pdf_url' => '', 'labels_pdf_url' => '', 'labels_4a3_pdf_url' => ''];
    }

    // -----------------------------------------------------------------

    private function safeJson(Response $response): mixed
    {
        try {
            return $response->json();
        } catch (Throwable) {
            return $response->body();
        }
    }

    private function extractError(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $message = $body['message'] ?? $body['error'] ?? $body['data']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
