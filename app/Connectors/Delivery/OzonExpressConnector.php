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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ozon Express Morocco (https://api.ozonexpress.ma). Auth is embedded in the
 * URL path (customers/{customer_id}/{api_key}/...), which is unusual and
 * dangerous to log verbatim — every log line and error message goes through
 * maskedUrl(), never the real built URL.
 *
 * Docs only show these endpoints; nothing here assumes an undocumented
 * response shape beyond what's read defensively with `?? null`.
 */
class OzonExpressConnector implements DeliveryProviderConnectorInterface
{
    private const BASE_HOST = 'https://api.ozonexpress.ma';
    private const CLIENT_HOST = 'https://client.ozonexpress.ma';

    public function __construct(private readonly DeliveryConnection $connection)
    {
        if ($connection->provider_code !== 'ozon') {
            throw new \InvalidArgumentException(
                "OzonExpressConnector requires provider_code 'ozon', got '{$connection->provider_code}'"
            );
        }
    }

    private function customerId(): string
    {
        return (string) ($this->connection->credential('customer_id') ?? '');
    }

    private function apiKey(): string
    {
        return (string) ($this->connection->credential('api_key') ?? '');
    }

    private function basePath(): string
    {
        return "/customers/{$this->customerId()}/{$this->apiKey()}";
    }

    /** Safe to log — never contains the real api_key. */
    private function maskedUrl(string $endpoint): string
    {
        return self::BASE_HOST . "/customers/{$this->customerId()}/****/" . ltrim($endpoint, '/');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_HOST . $this->basePath())
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function form(): PendingRequest
    {
        return $this->client()->asForm();
    }

    /**
     * Unlike every other endpoint, /cities is documented as a plain
     * top-level route (`https://api.ozonexpress.ma/cities`) — NOT nested
     * under `/customers/{id}/{key}/...`. It carries no credentials, so there
     * is nothing to mask for this one call.
     */
    private function citiesClient(): PendingRequest
    {
        return Http::baseUrl(self::BASE_HOST)
            ->connectTimeout(10)
            ->timeout(30)
            ->acceptJson();
    }

    /** @return array{ok: bool, message: string, raw?: mixed} */
    public function testConnection(): array
    {
        try {
            $response = $this->citiesClient()->get('/cities');

            if ($response->failed()) {
                $this->logCitiesIssue('test-connection', response: $response);

                return ['ok' => false, 'message' => "Ozon /cities returned HTTP {$response->status()}.", 'raw' => $this->safeJson($response)];
            }

            return ['ok' => true, 'message' => 'Connected.', 'raw' => $this->safeJson($response)];
        } catch (Throwable $e) {
            $this->logCitiesIssue('test-connection', exception: $e);

            return ['ok' => false, 'message' => "Could not reach Ozon Express: {$e->getMessage()}"];
        }
    }

    /**
     * @return array{ok: bool, cities: array<int, array{
     *     provider_city_id: string, city_name: string, city_ref: ?string,
     *     delivered_price: ?float, returned_price: ?float, refused_price: ?float, raw: mixed
     * }>, error?: string}
     */
    public function listCities(): array
    {
        try {
            $response = $this->citiesClient()->get('/cities');

            if ($response->failed()) {
                $this->logCitiesIssue('list-cities', response: $response);

                return ['ok' => false, 'cities' => [], 'error' => "Ozon /cities returned HTTP {$response->status()}."];
            }

            $body = $this->safeJson($response);
            $rows = $this->extractCityRows($body);

            if (! is_array($rows)) {
                $this->logCitiesIssue('list-cities', response: $response, note: 'rows key was not an array');

                return ['ok' => false, 'cities' => [], 'error' => 'Unrecognized Ozon /cities response shape (expected a list, "CITIES", "cities", or "data" key).'];
            }

            $cities = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Ozon's docs and observed responses disagree on casing —
                // accept CITY_ID/CITY_NAME (add-parcel's own vocabulary),
                // plain ID/NAME (the real /cities response), and lowercase
                // id/name/city_id/city_name.
                $id = $row['CITY_ID'] ?? $row['ID'] ?? $row['id'] ?? $row['city_id'] ?? null;
                $name = $row['CITY_NAME'] ?? $row['NAME'] ?? $row['name'] ?? $row['city_name'] ?? null;

                if ($id === null || $name === null) {
                    continue;
                }

                $cities[] = [
                    'provider_city_id' => (string) $id,
                    'city_name' => (string) $name,
                    'city_ref' => $this->stringOrNull($row['REF'] ?? $row['ref'] ?? null),
                    'delivered_price' => $this->floatOrNull($row['DELIVERED-PRICE'] ?? $row['delivered_price'] ?? null),
                    'returned_price' => $this->floatOrNull($row['RETURNED-PRICE'] ?? $row['returned_price'] ?? null),
                    'refused_price' => $this->floatOrNull($row['REFUSED-PRICE'] ?? $row['refused_price'] ?? null),
                    'raw' => $row,
                ];
            }

            if ($cities === [] && $rows !== []) {
                $this->logCitiesIssue('list-cities', response: $response, note: 'rows present but no row matched a known id/name key');
            }

            return ['ok' => true, 'cities' => $cities];
        } catch (Throwable $e) {
            $this->logCitiesIssue('list-cities', exception: $e);

            return ['ok' => false, 'cities' => [], 'error' => "Could not reach Ozon Express: {$e->getMessage()}"];
        }
    }

    /**
     * Ozon's real /cities response nests rows under an uppercase "CITIES"
     * object keyed by city id (`{"CITIES": {"37": {...}, "49": {...}}}`) —
     * array_values() turns that into a plain list. "cities"/"data" (lower)
     * and a bare top-level list are also accepted defensively.
     */
    private function extractCityRows(mixed $body): mixed
    {
        if (! is_array($body)) {
            return [];
        }

        if (isset($body['CITIES']) && is_array($body['CITIES'])) {
            return array_values($body['CITIES']);
        }

        if (isset($body['cities']) && is_array($body['cities'])) {
            return array_values($body['cities']);
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return array_values($body['data']);
        }

        return array_is_list($body) ? $body : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Diagnostic logging for the /cities endpoint only — safe because that
     * endpoint never carries the api_key. Logs HTTP status, content-type,
     * and a short body preview so a real parser/endpoint failure is
     * debuggable, without ever risking a credential leak.
     */
    private function logCitiesIssue(string $context, ?Response $response = null, ?Throwable $exception = null, ?string $note = null): void
    {
        Log::warning('Ozon Express /cities issue', array_filter([
            'context' => $context,
            'connection_id' => $this->connection->id,
            'status' => $response?->status(),
            'content_type' => $response?->header('Content-Type'),
            'body_preview' => $response !== null ? mb_substr((string) $response->body(), 0, 200) : null,
            'exception' => $exception?->getMessage(),
            'note' => $note,
        ], static fn ($v) => $v !== null));
    }

    /**
     * $options must already carry the resolved fields (city mapping etc. is
     * the caller's job — this connector performs no lookups):
     * receiver_name, phone, provider_city_id, address, cod_amount, note?,
     * parcel_stock?, parcel_open?, fragile?, replace?, parcel_nature?, products?, tracking_number?
     *
     * `parcel_stock` MUST already be the final, resolved string (typically
     * from OzonShipmentService::resolveParcelStock(), which reads the
     * connection's `default_parcel_stock` setting — see that method for why
     * `array_key_exists`, not `??`/`?:`/`empty()`, is required to keep an
     * explicit "0" as "0"). This connector only applies a last-resort "0"
     * fallback (never "1" — a stock parcel requires product details this
     * connector cannot invent) if the field is somehow still missing.
     *
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, tracking_number?: string, provider_shipment_id?: string, raw: mixed, error?: string, debug?: array<string, mixed>}
     */
    public function createShipment(Order|PosOrder $order, DeliveryConnection $connection, array $options = []): array
    {
        $payload = array_filter([
            'tracking-number' => $options['tracking_number'] ?? null,
            'parcel-receiver' => $options['receiver_name'] ?? null,
            'parcel-phone' => $options['phone'] ?? null,
            'parcel-city' => $options['provider_city_id'] ?? null,
            'parcel-address' => $options['address'] ?? null,
            'parcel-note' => $options['note'] ?? null,
            'parcel-price' => isset($options['cod_amount']) ? self::formatParcelPrice($options['cod_amount']) : null,
            'parcel-nature' => $options['parcel_nature'] ?? null,
            'parcel-stock' => $this->normalizeParcelStock($options['parcel_stock'] ?? null),
            'parcel-open' => $this->normalizeParcelOpen($options['parcel_open'] ?? null),
            'parcel-fragile' => $this->normalizeBooleanFlag($options['fragile'] ?? null),
            'parcel-replace' => $this->normalizeBooleanFlag($options['replace'] ?? null),
            'products' => isset($options['products']) && $options['products'] !== [] ? json_encode($options['products']) : null,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $response = $this->form()->acceptJson()->post('/add-parcel', $payload);
            $body = $this->safeJson($response);

            // Ozon returning an HTML/plain-text error page (or anything that
            // isn't a JSON object) must never be silently read as "no
            // tracking number" — it's a different failure mode entirely.
            if (! is_array($body)) {
                $this->logCreateShipmentIssue($payload, $response, null, 'response body was not JSON');

                return [
                    'ok' => false,
                    'raw' => $body,
                    'error' => 'Ozon create parcel returned a non-JSON response.',
                    'debug' => $this->debugInfo($payload, $response, null),
                ];
            }

            if ($response->failed()) {
                $businessError = $this->extractProviderBusinessError($body);
                $this->logCreateShipmentIssue($payload, $response, $body);

                return [
                    'ok' => false,
                    'raw' => $body,
                    'error' => $businessError !== null
                        ? "Ozon refused parcel: {$businessError}"
                        : ($this->extractError($body) ?? "Ozon returned HTTP {$response->status()}."),
                    'debug' => $this->debugInfo($payload, $response, $body),
                ];
            }

            $trackingNumber = $this->extractTrackingNumber($body);

            // A tracking number in the body is trusted as success on its
            // own — Ozon can return HTTP 200 for a genuine business-rule
            // rejection (ADD-PARCEL.RESULT: "ERROR"), so the absence of a
            // tracking number is checked for THAT specific, precise reason
            // before ever falling back to the generic "missing tracking
            // number" message.
            if ($trackingNumber === null) {
                $businessError = $this->extractProviderBusinessError($body);

                if ($businessError !== null) {
                    $this->logCreateShipmentIssue($payload, $response, $body, "provider RESULT=ERROR: {$businessError}");

                    return [
                        'ok' => false,
                        'raw' => $body,
                        'error' => "Ozon refused parcel: {$businessError}",
                        'debug' => $this->debugInfo($payload, $response, $body),
                    ];
                }

                $this->logCreateShipmentIssue($payload, $response, $body, 'no tracking number key matched');
                $providerError = $this->extractError($body);

                return [
                    'ok' => false,
                    'raw' => $body,
                    'error' => $providerError !== null
                        ? "Ozon rejected the parcel: {$providerError}"
                        : 'Ozon response did not include a tracking number.',
                    'debug' => $this->debugInfo($payload, $response, $body),
                ];
            }

            // add-parcel returning HTTP 200 with a tracking number is NOT
            // trusted as the final word — Ozon has been observed to hand
            // back a tracking number for a parcel its own dashboard search
            // cannot find. A second, independent provider call (parcel-info,
            // falling back to tracking) must also recognize the same
            // tracking number before this project treats the shipment as
            // real; see verifyShipment().
            $addParcel = $this->addParcelResultAndMessage($body);
            $verification = $this->verifyShipment($trackingNumber);

            return [
                'ok' => true,
                'tracking_number' => $trackingNumber,
                'provider_shipment_id' => $trackingNumber,
                'raw' => $body,
                'add_parcel_result' => $addParcel['result'],
                'add_parcel_message' => $addParcel['message'],
                'verification' => $verification,
            ];
        } catch (Throwable $e) {
            $this->logFailure('add-parcel', $e);

            return ['ok' => false, 'raw' => null, 'error' => "Could not reach Ozon Express: {$e->getMessage()}"];
        }
    }

    /** @return array{result: ?string, message: ?string} */
    private function addParcelResultAndMessage(array $body): array
    {
        foreach (['ADD-PARCEL', 'add-parcel'] as $wrapperKey) {
            if (isset($body[$wrapperKey]) && is_array($body[$wrapperKey])) {
                $result = $body[$wrapperKey]['RESULT'] ?? $body[$wrapperKey]['result'] ?? null;
                $message = $body[$wrapperKey]['MESSAGE'] ?? $body[$wrapperKey]['message'] ?? $body['MESSAGE'] ?? $body['message'] ?? null;

                return ['result' => $result !== null ? (string) $result : null, 'message' => $message !== null ? (string) $message : null];
            }
        }

        $result = $body['RESULT'] ?? $body['result'] ?? null;
        $message = $body['MESSAGE'] ?? $body['message'] ?? null;

        return ['result' => $result !== null ? (string) $result : null, 'message' => $message !== null ? (string) $message : null];
    }

    /**
     * Verifies a tracking number that add-parcel just returned, by calling
     * parcel-info and — only if that doesn't confirm it — falling back to
     * tracking. A shipment is verified only if one of the two independently
     * recognizes the same tracking number with no provider error/not-found
     * signal; HTTP 200 + a tracking number from add-parcel alone is
     * deliberately NOT sufficient (see createShipment()'s call site).
     *
     * @return array{
     *     verified: bool,
     *     parcel_info_http_status: ?int, parcel_info_provider_message: ?string, parcel_info_raw: mixed,
     *     tracking_http_status: ?int, tracking_provider_message: ?string, tracking_raw: mixed,
     *     verification_error: ?string,
     * }
     */
    public function verifyShipment(string $trackingNumber): array
    {
        $parcelInfo = $this->probeTrackingNumber('/parcel-info', $trackingNumber);

        $tracking = $parcelInfo['confirmed']
            ? ['confirmed' => false, 'http_status' => null, 'provider_message' => null, 'raw' => null]
            : $this->probeTrackingNumber('/tracking', $trackingNumber);

        $verified = $parcelInfo['confirmed'] || $tracking['confirmed'];

        return [
            'verified' => $verified,
            'parcel_info_http_status' => $parcelInfo['http_status'],
            'parcel_info_provider_message' => $parcelInfo['provider_message'],
            'parcel_info_raw' => $parcelInfo['raw'],
            'tracking_http_status' => $tracking['http_status'],
            'tracking_provider_message' => $tracking['provider_message'],
            'tracking_raw' => $tracking['raw'],
            'verification_error' => $verified
                ? null
                : ($tracking['provider_message'] ?? $parcelInfo['provider_message'] ?? 'Ozon could not confirm this parcel via parcel-info or tracking.'),
        ];
    }

    /**
     * One verification probe (parcel-info OR tracking) for a single
     * tracking number. "confirmed" requires: HTTP success, a decodable
     * non-empty JSON body, and no provider error/not-found signal in it —
     * absence of a negative signal, since Ozon's docs don't specify a
     * positive "found" marker for these two endpoints.
     *
     * @return array{confirmed: bool, http_status: ?int, provider_message: ?string, raw: mixed}
     */
    private function probeTrackingNumber(string $endpoint, string $trackingNumber): array
    {
        try {
            $response = $this->form()->acceptJson()->post($endpoint, ['tracking-number' => $trackingNumber]);
            $body = $this->safeJson($response);

            if ($response->failed()) {
                return [
                    'confirmed' => false,
                    'http_status' => $response->status(),
                    'provider_message' => is_array($body) ? $this->extractVerificationError($body) : "Ozon returned HTTP {$response->status()}.",
                    'raw' => $body,
                ];
            }

            if (! is_array($body) || $body === []) {
                return [
                    'confirmed' => false,
                    'http_status' => $response->status(),
                    'provider_message' => 'Ozon returned an empty or non-JSON response.',
                    'raw' => $body,
                ];
            }

            $error = $this->extractVerificationError($body);

            return [
                'confirmed' => $error === null,
                'http_status' => $response->status(),
                'provider_message' => $error,
                'raw' => $body,
            ];
        } catch (Throwable $e) {
            $this->logFailure($endpoint, $e);

            return ['confirmed' => false, 'http_status' => null, 'provider_message' => 'Could not reach Ozon Express.', 'raw' => null];
        }
    }

    /**
     * parcel-info/tracking business-error check — mirrors
     * extractProviderBusinessError()'s ADD-PARCEL wrapper pattern but also
     * checks a bare (unwrapped) RESULT/MESSAGE pair and falls back to the
     * generic extractError() keys, since Ozon's docs don't specify whether
     * these two endpoints nest their response under a named wrapper key the
     * way add-parcel does.
     */
    private function extractVerificationError(array $body): ?string
    {
        foreach (['PARCEL-INFO', 'parcel-info', 'TRACKING', 'tracking', 'ADD-PARCEL', 'add-parcel'] as $wrapperKey) {
            if (! isset($body[$wrapperKey]) || ! is_array($body[$wrapperKey])) {
                continue;
            }

            $result = $body[$wrapperKey]['RESULT'] ?? $body[$wrapperKey]['result'] ?? null;

            if ($result === null || strtoupper((string) $result) === 'SUCCESS') {
                continue;
            }

            $message = $body[$wrapperKey]['MESSAGE'] ?? $body[$wrapperKey]['message'] ?? null;

            return is_string($message) && $message !== '' ? $message : 'Ozon could not confirm this parcel.';
        }

        $result = $body['RESULT'] ?? $body['result'] ?? null;

        if ($result !== null && strtoupper((string) $result) !== 'SUCCESS') {
            $message = $body['MESSAGE'] ?? $body['message'] ?? null;

            return is_string($message) && $message !== '' ? $message : 'Ozon could not confirm this parcel.';
        }

        return $this->extractError($body);
    }

    /**
     * Ozon's own money format: a plain integer MAD string — no comma, no
     * currency label, no thousands separator. Accepts an int/float or any
     * reasonably-shaped string (including a LOCALIZED display string like
     * "93,99" or "1,250.00") and normalizes it defensively; the connector
     * must NEVER forward a formatted display value as-is — Ozon rejects a
     * comma in parcel-price outright ("Price without commas").
     *
     * Rounds to the nearest whole MAD (round-half-away-from-zero, PHP's
     * default) rather than truncating/ceiling — this project has no other
     * established COD-rounding convention; revisit here if one is adopted.
     */
    public static function formatParcelPrice(int|float|string $amount): string
    {
        if (is_int($amount) || is_float($amount)) {
            return (string) (int) round($amount);
        }

        $value = trim($amount);

        // Strip everything except digits, comma, dot and a leading minus —
        // removes currency labels ("MAD"), regular AND non-breaking spaces,
        // and any other stray characters.
        $value = preg_replace('/[^\d,.\-]/u', '', $value) ?? $value;

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                // Comma is the decimal separator, dot(s) are thousands
                // separators: "1.250,00" -> "1250.00".
                $value = str_replace('.', '', $value);
                $value = substr_replace($value, '.', (int) strrpos($value, ','), 1);
            } else {
                // Dot is the decimal separator, comma(s) are thousands
                // separators: "1,250.00" -> "1250.00".
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            // Only a comma present — Ozon's own complaint ("Price without
            // commas") is exactly this case: "93,99" -> "93.99".
            $value = str_replace(',', '.', $value);
        }

        $float = is_numeric($value) ? (float) $value : 0.0;

        return (string) (int) round($float);
    }

    /**
     * Ozon can return HTTP 200 for a genuine business-rule rejection, e.g.
     * {"ADD-PARCEL": {"RESULT": "ERROR", "MESSAGE": "Price without commas"}}
     * — HTTP status alone is never sufficient to decide success.
     */
    private function extractProviderBusinessError(array $body): ?string
    {
        foreach (['ADD-PARCEL', 'add-parcel'] as $wrapperKey) {
            if (! isset($body[$wrapperKey]) || ! is_array($body[$wrapperKey])) {
                continue;
            }

            $result = $body[$wrapperKey]['RESULT'] ?? $body[$wrapperKey]['result'] ?? null;

            if ($result === null || strtoupper((string) $result) === 'SUCCESS') {
                continue;
            }

            $message = $body[$wrapperKey]['MESSAGE'] ?? $body[$wrapperKey]['message'] ?? null;

            return is_string($message) && $message !== '' ? $message : 'Ozon rejected the parcel.';
        }

        return null;
    }

    /**
     * Last-resort guard only — the REAL resolution (which must treat an
     * explicit "0" as a real value, not "empty") happens in
     * OzonShipmentService::resolveParcelStock() against the connection's
     * settings, before this connector is ever called. This never invents
     * "1" (a stock parcel this connector has no product data for) — it
     * falls back to "0" like the rest of this project's parcel-stock
     * default policy.
     */
    private function normalizeParcelStock(mixed $value): string
    {
        return $value === null || $value === '' ? '0' : (string) $value;
    }

    /**
     * Last-resort guard only — the real resolution is
     * OzonShipmentService::resolveParcelOpen(). Ozon's documented values are
     * "1" (ouvrir le colis) and "2" (ne pas ouvrir), default "1"; anything
     * else falls back to "1" rather than being forwarded unrecognized.
     */
    private function normalizeParcelOpen(mixed $value): string
    {
        $value = $value === null || $value === '' ? '1' : (string) $value;

        return in_array($value, ['1', '2'], true) ? $value : '1';
    }

    /**
     * Last-resort guard only — the real resolution is
     * OzonShipmentService::resolveBooleanFlag(). Ozon's documented default
     * for both fragile and replace is "0"; a PHP bool is normalized to the
     * literal string Ozon expects rather than being form-encoded as-is.
     */
    private function normalizeBooleanFlag(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) ($value ?? '0');

        return $value === '1' || $value === 'true' ? '1' : '0';
    }

    /**
     * Every documented (and a few observed) key casing/nesting variant for
     * the tracking number. Ozon's response vocabulary is inconsistent
     * across endpoints (UPPER-HYPHEN like /cities' CITY_ID, but also plain
     * casing has been observed) — never assume only one convention.
     */
    private function extractTrackingNumber(array $body): ?string
    {
        $keys = ['TRACKING-NUMBER', 'tracking-number', 'tracking_number', 'trackingNumber', 'TRACKING_NUMBER'];

        // Bare body, the generic single-wrapper-key unwrap, and explicit
        // named wrappers Ozon is known to nest responses under.
        $candidates = [$body, $this->unwrap($body)];

        foreach (['data', 'parcel', 'add-parcel', 'ADD-PARCEL'] as $wrapperKey) {
            if (isset($body[$wrapperKey]) && is_array($body[$wrapperKey])) {
                $candidates[] = $body[$wrapperKey];

                // Observed real shape: {"ADD-PARCEL": {"RESULT": "SUCCESS",
                // "NEW-PARCEL": {"TRACKING-NUMBER": "BML..."}}} — the
                // tracking number nested one level deeper than the other
                // known shapes.
                foreach (['NEW-PARCEL', 'new-parcel', 'new_parcel'] as $nestedKey) {
                    if (isset($body[$wrapperKey][$nestedKey]) && is_array($body[$wrapperKey][$nestedKey])) {
                        $candidates[] = $body[$wrapperKey][$nestedKey];
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach ($keys as $key) {
                if (isset($candidate[$key]) && $candidate[$key] !== '') {
                    return (string) $candidate[$key];
                }
            }
        }

        return null;
    }

    /**
     * Everything needed to answer "what did we actually send, and what did
     * Ozon actually say back" without ever exposing the api_key (never in
     * the form body — only the URL carries it, and the URL is never logged
     * or included here) or the full request URL.
     *
     * @param  array<string, mixed>  $payload  the exact form-data payload that was POSTed
     * @return array{
     *     http_status: int, content_type: ?string, response_keys: array<int, string>, response_preview: string,
     *     provider_message: ?string,
     *     parcel_stock_sent: ?string, parcel_price_sent: ?string, parcel_city_sent: ?string,
     *     parcel_open_sent: ?string, parcel_fragile_sent: ?string, parcel_replace_sent: ?string,
     *     receiver_present: bool, phone_present: bool, address_present: bool,
     *     has_products: bool, products_count: int, product_refs_preview: array<int, string>,
     *     products_json_preview: ?string,
     * }
     */
    private function debugInfo(array $payload, Response $response, ?array $body): array
    {
        $products = isset($payload['products']) ? json_decode((string) $payload['products'], true) : null;
        $productsCount = is_array($products) ? count($products) : 0;
        $productRefs = is_array($products)
            ? array_values(array_filter(array_map(
                static fn ($p) => is_array($p) ? ($p['ref'] ?? null) : null,
                $products,
            )))
            : [];

        return [
            'http_status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'response_keys' => $body !== null ? array_keys($body) : [],
            // Shorter than the server log's preview — this one is safe to
            // show a user, so keep it brief rather than dumping the payload.
            'response_preview' => mb_substr((string) $response->body(), 0, 500),
            'provider_message' => $body !== null ? ($this->extractProviderBusinessError($body) ?? $this->extractError($body)) : null,
            'parcel_stock_sent' => $payload['parcel-stock'] ?? null,
            'parcel_price_sent' => $payload['parcel-price'] ?? null,
            'parcel_city_sent' => $payload['parcel-city'] ?? null,
            'parcel_open_sent' => $payload['parcel-open'] ?? null,
            'parcel_fragile_sent' => $payload['parcel-fragile'] ?? null,
            'parcel_replace_sent' => $payload['parcel-replace'] ?? null,
            'receiver_present' => filled($payload['parcel-receiver'] ?? null),
            'phone_present' => filled($payload['parcel-phone'] ?? null),
            'address_present' => filled($payload['parcel-address'] ?? null),
            'has_products' => $productsCount > 0,
            'products_count' => $productsCount,
            // First few refs only — enough to spot-check without dumping
            // the whole order's SKU list into a flash-session error.
            'product_refs_preview' => array_slice($productRefs, 0, 5),
            'products_json_preview' => isset($payload['products']) ? mb_substr((string) $payload['products'], 0, 300) : null,
        ];
    }

    /**
     * add-parcel never carries the api_key in its BODY (only the URL does,
     * which is never logged here) — logging the full outgoing payload is
     * safe and is exactly what makes "was parcel-stock really sent as 0"
     * debuggable without guessing.
     *
     * @param  array<string, mixed>  $payload  the exact form-data payload that was POSTed
     */
    private function logCreateShipmentIssue(array $payload, Response $response, ?array $body, ?string $note = null): void
    {
        Log::warning('Ozon Express add-parcel issue', array_filter([
            'connection_id' => $this->connection->id,
            'sent_payload' => $payload,
            'status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'body_preview' => mb_substr((string) $response->body(), 0, 1000),
            'response_keys' => $body !== null ? array_keys($body) : null,
            'note' => $note,
        ], static fn ($v) => $v !== null));
    }

    /** @return array{ok: bool, raw: mixed, error?: string} */
    public function getShipmentInfo(Shipment $shipment): array
    {
        return $this->post('/parcel-info', ['tracking-number' => $shipment->tracking_number]);
    }

    /** @return array{ok: bool, provider_status?: string, normalized_status?: string, raw: mixed, error?: string} */
    public function trackShipment(Shipment $shipment): array
    {
        $result = $this->post('/tracking', ['tracking-number' => $shipment->tracking_number]);

        if (! $result['ok']) {
            return $result;
        }

        $data = $this->unwrap($result['raw']);
        $providerStatus = $data['status'] ?? $data['STATUS'] ?? $data['state'] ?? null;

        if ($providerStatus === null) {
            return ['ok' => false, 'raw' => $result['raw'], 'error' => 'Ozon tracking response had no status.'];
        }

        return [
            'ok' => true,
            'provider_status' => (string) $providerStatus,
            'normalized_status' => $this->normalizeStatus((string) $providerStatus),
            'raw' => $result['raw'],
        ];
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @return array<string, array{ok: bool, provider_status?: string, normalized_status?: string, raw: mixed, error?: string}>
     */
    public function trackShipmentsBulk(Collection $shipments): array
    {
        $trackingNumbers = $shipments->pluck('tracking_number')->filter()->values()->all();

        if ($trackingNumbers === []) {
            return [];
        }

        try {
            $response = $this->client()->asJson()->acceptJson()->post('/tracking', ['tracking-number' => $trackingNumbers]);
            $body = $this->safeJson($response);

            if ($response->failed()) {
                $error = "Ozon returned {$response->status()}.";

                return collect($trackingNumbers)->mapWithKeys(fn ($tn) => [$tn => ['ok' => false, 'raw' => $body, 'error' => $error]])->all();
            }

            $rows = $this->unwrap($body);
            $results = [];

            foreach ($trackingNumbers as $tn) {
                $row = is_array($rows) ? ($rows[$tn] ?? null) : null;
                $providerStatus = is_array($row) ? ($row['status'] ?? $row['STATUS'] ?? null) : null;

                $results[$tn] = $providerStatus === null
                    ? ['ok' => false, 'raw' => $row, 'error' => 'No status returned for this parcel.']
                    : [
                        'ok' => true,
                        'provider_status' => (string) $providerStatus,
                        'normalized_status' => $this->normalizeStatus((string) $providerStatus),
                        'raw' => $row,
                    ];
            }

            return $results;
        } catch (Throwable $e) {
            $this->logFailure('tracking-bulk', $e);

            return collect($trackingNumbers)->mapWithKeys(
                fn ($tn) => [$tn => ['ok' => false, 'raw' => null, 'error' => 'Could not reach Ozon Express.']]
            )->all();
        }
    }

    /** @return array{ok: bool, provider_ref?: string, raw: mixed, error?: string} */
    public function createDeliveryNote(DeliveryConnection $connection, string $ref): array
    {
        $result = $this->post('/add-delivery-note', ['Ref' => $ref]);

        if (! $result['ok']) {
            return $result;
        }

        return ['ok' => true, 'provider_ref' => $ref, 'raw' => $result['raw']];
    }

    /**
     * @param  array<int, string>  $trackingNumbers
     * @return array{ok: bool, raw: mixed, error?: string}
     */
    public function addParcelsToDeliveryNote(DeliveryConnection $connection, string $ref, array $trackingNumbers): array
    {
        return $this->post('/add-parcel-to-delivery-note', ['Ref' => $ref, 'Codes' => $trackingNumbers]);
    }

    /** @return array{ok: bool, raw: mixed, error?: string} */
    public function saveDeliveryNote(DeliveryConnection $connection, string $ref): array
    {
        return $this->post('/save-delivery-note', ['Ref' => $ref]);
    }

    /**
     * The BL PDF endpoints on client.ozonexpress.ma. These are URL strings
     * only — never fetched here; the caller decides whether to pull the
     * bytes server-side (see FulfillmentDocumentService).
     *
     * The 4-up ticket sheet's real path is UNVERIFIED: existing code used
     * `-4A3`, an Ozon screenshot shows `-4-4`. Both candidates are returned
     * so the caller can try each and keep whichever returns a real PDF —
     * neither is removed until one is confirmed against the live API.
     *
     * @return array{pdf_url: string, labels_pdf_url: string, labels_4x4_pdf_url: string, labels_4a3_pdf_url: string}
     */
    public function getDeliveryNotePdfUrls(string $ref): array
    {
        $encoded = urlencode($ref);

        return [
            'pdf_url' => self::CLIENT_HOST . "/pdf-delivery-note?dn-ref={$encoded}",
            'labels_pdf_url' => self::CLIENT_HOST . "/pdf-delivery-note-tickets?dn-ref={$encoded}",
            'labels_4x4_pdf_url' => self::CLIENT_HOST . "/pdf-delivery-note-tickets-4-4?dn-ref={$encoded}",
            'labels_4a3_pdf_url' => self::CLIENT_HOST . "/pdf-delivery-note-tickets-4A3?dn-ref={$encoded}",
        ];
    }

    public function normalizeStatus(string $providerStatus): string
    {
        return OzonStatusMapper::normalize($providerStatus);
    }

    // -------------------------------------------------------------------

    /** @param array<string, mixed> $payload @return array{ok: bool, raw: mixed, error?: string} */
    private function post(string $endpoint, array $payload): array
    {
        try {
            $response = $this->form()->acceptJson()->post($endpoint, array_filter($payload, static fn ($v) => $v !== null));
            $body = $this->safeJson($response);

            if ($response->failed()) {
                return ['ok' => false, 'raw' => $body, 'error' => $this->extractError($body) ?? "Ozon returned {$response->status()}."];
            }

            return ['ok' => true, 'raw' => $body];
        } catch (Throwable $e) {
            $this->logFailure($endpoint, $e);

            return ['ok' => false, 'raw' => null, 'error' => 'Could not reach Ozon Express.'];
        }
    }

    private function safeJson(Response $response): mixed
    {
        try {
            return $response->json();
        } catch (Throwable) {
            return $response->body();
        }
    }

    /** Ozon sometimes nests the payload under a top-level key (e.g. {"add-parcel": {...}}); unwrap defensively. */
    private function unwrap(mixed $body): mixed
    {
        if (! is_array($body)) {
            return $body;
        }

        if (count($body) === 1 && is_array(reset($body)) && ! array_is_list($body)) {
            return reset($body);
        }

        return $body;
    }

    private function extractError(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $data = $this->unwrap($body);
        $data = is_array($data) ? $data : $body;

        $message = $data['message'] ?? $data['error'] ?? $data['ERROR'] ?? $data['MESSAGE'] ?? $data['Message']
            ?? $data['ERROR-MESSAGE'] ?? $body['message'] ?? $body['error'] ?? $body['ERROR'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function logFailure(string $endpoint, Throwable $e): void
    {
        Log::warning('Ozon Express request failed', [
            'endpoint' => $this->maskedUrl($endpoint),
            'connection_id' => $this->connection->id,
            'error' => $e->getMessage(),
        ]);
    }
}
