<?php

declare(strict_types=1);

use App\Connectors\Delivery\OzonExpressConnector;
use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| OzonExpressConnector::createShipment() response parsing — every documented
| tracking-number key casing/nesting, non-JSON responses, and provider
| error messages.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $owner->id]);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'super-secret-key'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $this->order = Order::factory()->make(['store_id' => $this->store->id]);
    $this->connector = new OzonExpressConnector($this->connection);
    $this->baseOptions = [
        'receiver_name' => 'Sara', 'phone' => '0611223344', 'provider_city_id' => '17',
        'address' => '12 Rue Test', 'cod_amount' => 250,
    ];
});

dataset('tracking_number_shapes', [
    'bare TRACKING-NUMBER' => [['TRACKING-NUMBER' => 'OZE1'], 'OZE1'],
    'bare tracking-number (lowercase hyphen)' => [['tracking-number' => 'OZE2'], 'OZE2'],
    'bare tracking_number (snake_case)' => [['tracking_number' => 'OZE3'], 'OZE3'],
    'bare trackingNumber (camelCase)' => [['trackingNumber' => 'OZE4'], 'OZE4'],
    'bare TRACKING_NUMBER (upper snake)' => [['TRACKING_NUMBER' => 'OZE5'], 'OZE5'],
    'nested under data' => [['data' => ['TRACKING-NUMBER' => 'OZE6']], 'OZE6'],
    'nested under parcel' => [['parcel' => ['TRACKING-NUMBER' => 'OZE7']], 'OZE7'],
    'nested under add-parcel wrapper' => [['add-parcel' => ['TRACKING-NUMBER' => 'OZE8']], 'OZE8'],
]);

it('extracts the tracking number from every documented response shape', function (array $response, string $expected) {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response($response, 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeTrue()
        ->and($result['tracking_number'])->toBe($expected);
})->with('tracking_number_shapes');

it('stores the full raw payload on success', function () {
    $response = ['TRACKING-NUMBER' => 'OZE1', 'RECEIVER' => 'Sara', 'CITY_ID' => 17, 'PRICE' => 250];
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response($response, 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['raw'])->toBe($response);
});

it('returns a clear, distinct error for a non-JSON (HTML) response instead of "did not return a tracking number"', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response('<html><body>502 Bad Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Ozon create parcel returned a non-JSON response.')
        ->and($result['error'])->not->toContain('did not return a tracking number');
});

it('shows the real provider error message when Ozon rejects the parcel with an HTTP error status', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['error' => 'Invalid parcel-city'], 422)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Invalid parcel-city');
});

it('shows the provider error message when the parcel is rejected without a tracking number even on HTTP 200', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['MESSAGE' => 'Customer ID quota exceeded'], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('Customer ID quota exceeded');
});

it('falls back to the generic missing-tracking-number message only when there is truly no provider error either', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['RECEIVER' => 'Sara'], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Ozon response did not include a tracking number.');
});

it('defaults parcel-stock to 0 at the connector level when it is missing from options entirely', function () {
    // The real default resolution (array_key_exists against connection
    // settings) lives in OzonShipmentService::resolveParcelStock() — this
    // is just the connector's own last-resort guard when called directly.
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    $this->connector->createShipment($this->order, $this->connection, $this->baseOptions); // no parcel_stock key at all

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === '0');
});

it('uses the caller-provided parcel-stock over the "0" default when one is set', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    $this->connector->createShipment($this->order, $this->connection, $this->baseOptions + ['parcel_stock' => 'WAREHOUSE-A']);

    Http::assertSent(fn ($request) => ($request['parcel-stock'] ?? null) === 'WAREHOUSE-A');
});

it('sends every required form-data field with the exact Ozon-documented names', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/add-parcel')) {
            return false;
        }

        expect($request['parcel-receiver'])->toBe('Sara')
            ->and($request['parcel-phone'])->toBe('0611223344')
            ->and($request['parcel-city'])->toBe('17')
            ->and($request['parcel-address'])->toBe('12 Rue Test')
            ->and((float) $request['parcel-price'])->toBe(250.0)
            ->and($request['parcel-stock'])->toBe('0');

        return true;
    });
});

it('never logs the api_key when add-parcel fails to parse', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response('not json at all', 200)]);
    Log::spy();

    $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        expect($message . json_encode($context))->not->toContain('super-secret-key');

        return true;
    });
});
