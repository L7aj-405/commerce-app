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
| Ozon can return HTTP 200 for a genuine business-rule rejection —
| ADD-PARCEL.RESULT: "ERROR" — which must be treated as a failure with the
| exact provider message, never the generic "no tracking number" text.
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
        'address' => '12 Rue Test', 'cod_amount' => 94,
    ];
});

it('treats HTTP 200 with ADD-PARCEL.RESULT=ERROR as a failure, not a success', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => [
            'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
            'RESULT' => 'ERROR',
            'MESSAGE' => 'Price without commas',
        ],
    ], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse();
});

it('shows the exact provider MESSAGE prefixed with "Ozon refused parcel:"', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => [
            'CUSTOMER' => ['RESULT' => 'SUCCESS', 'MESSAGE' => 'Valid Customer'],
            'RESULT' => 'ERROR',
            'MESSAGE' => 'Price without commas',
        ],
    ], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['error'])->toBe('Ozon refused parcel: Price without commas');
});

it('does not show the generic "no tracking number" message when the provider returned an explicit error', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Price without commas'],
    ], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['error'])->not->toBe('Ozon response did not include a tracking number.')
        ->and($result['error'])->not->toContain('did not include a tracking number');
});

it('still creates a shipment normally when ADD-PARCEL.RESULT is SUCCESS and a tracking number is present', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => [
            'TRACKING-NUMBER' => 'OZE123456789',
            'RESULT' => 'SUCCESS',
            'MESSAGE' => 'Parcel added',
        ],
    ], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeTrue()
        ->and($result['tracking_number'])->toBe('OZE123456789');
});

it('trusts a present tracking number as success even without an explicit RESULT=SUCCESS flag', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['TRACKING-NUMBER' => 'OZE999'],
    ], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeTrue()
        ->and($result['tracking_number'])->toBe('OZE999');
});

it('also treats a business error as a failure when it arrives alongside a non-2xx HTTP status', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid parcel-city'],
    ], 422)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Ozon refused parcel: Invalid parcel-city');
});

it('carries safe debug info (http status, response keys) without the api_key', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Price without commas'],
    ], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['debug']['http_status'])->toBe(200)
        ->and($result['debug']['response_keys'])->toBe(['ADD-PARCEL'])
        ->and(json_encode($result['debug']))->not->toContain('super-secret-key')
        ->and(json_encode($result['debug']))->not->toContain('CUST123');
});

it('never logs the api_key when a business error occurs', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response([
        'ADD-PARCEL' => ['RESULT' => 'ERROR', 'MESSAGE' => 'Price without commas'],
    ], 200)]);
    Log::spy();

    $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        expect($message . json_encode($context))->not->toContain('super-secret-key');

        return true;
    });
});

it('does not misfire the business-error path for a flat (non-ADD-PARCEL-wrapped) response missing a tracking number', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['RECEIVER' => 'Sara'], 200)]);

    $result = $this->connector->createShipment($this->order, $this->connection, $this->baseOptions);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Ozon response did not include a tracking number.');
});
