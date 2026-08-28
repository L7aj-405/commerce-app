<?php

declare(strict_types=1);

use App\Connectors\Delivery\OzonExpressConnector;
use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| parcel-price must always be a clean integer MAD string — never a
| localized/comma-formatted display value. Ozon rejects a comma outright
| ("Price without commas").
|--------------------------------------------------------------------------
*/

dataset('parcel_price_inputs', [
    'float 93.99 rounds to 94' => [93.99, '94'],
    'localized comma "93,99" rounds to 94' => ['93,99', '94'],
    'plain int 250' => [250, '250'],
    'decimal string "250.00"' => ['250.00', '250'],
    'currency-suffixed comma "93,99 MAD"' => ['93,99 MAD', '94'],
    'thousands-dot + comma-decimal "1.250,00"' => ['1.250,00', '1250'],
    'thousands-comma + dot-decimal "1,250.00"' => ['1,250.00', '1250'],
    'space thousands + comma decimal "1 250,00"' => ['1 250,00', '1250'],
    'non-breaking-space thousands "1' . "\u{00A0}" . '250,00"' => ["1\u{00A0}250,00", '1250'],
    'zero' => [0, '0'],
    'already-clean string "100"' => ['100', '100'],
    'half-up rounding "93.5"' => ['93.5', '94'],
]);

it('formats every input shape to a clean integer MAD string', function ($input, string $expected) {
    expect(OzonExpressConnector::formatParcelPrice($input))->toBe($expected);
})->with('parcel_price_inputs');

it('never contains a comma in its output, for any input', function ($input) {
    expect(OzonExpressConnector::formatParcelPrice($input))->not->toContain(',');
})->with([
    [93.99], ['93,99'], ['1,250.00'], ['1 250,00'], [250], ['250.00'],
]);

beforeEach(function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $owner->id]);
    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);
    $this->order = Order::factory()->make(['store_id' => $this->store->id]);
});

it('sends parcel-price "94" when cod_amount is the order total 93.99', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    (new OzonExpressConnector($this->connection))->createShipment($this->order, $this->connection, [
        'receiver_name' => 'Sara', 'phone' => '0611223344', 'provider_city_id' => '17',
        'address' => 'addr', 'cod_amount' => 93.99,
    ]);

    Http::assertSent(fn ($request) => ($request['parcel-price'] ?? null) === '94');
});

it('sends parcel-price "94" when cod_amount arrives as the localized string "93,99"', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    (new OzonExpressConnector($this->connection))->createShipment($this->order, $this->connection, [
        'receiver_name' => 'Sara', 'phone' => '0611223344', 'provider_city_id' => '17',
        'address' => 'addr', 'cod_amount' => '93,99',
    ]);

    Http::assertSent(fn ($request) => ($request['parcel-price'] ?? null) === '94');
});

it('sends parcel-price "250" for "250.00"', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    (new OzonExpressConnector($this->connection))->createShipment($this->order, $this->connection, [
        'receiver_name' => 'Sara', 'phone' => '0611223344', 'provider_city_id' => '17',
        'address' => 'addr', 'cod_amount' => '250.00',
    ]);

    Http::assertSent(fn ($request) => ($request['parcel-price'] ?? null) === '250');
});

it('never sends a comma character anywhere in the parcel-price form field', function () {
    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);

    (new OzonExpressConnector($this->connection))->createShipment($this->order, $this->connection, [
        'receiver_name' => 'Sara', 'phone' => '0611223344', 'provider_city_id' => '17',
        'address' => 'addr', 'cod_amount' => '1,250.99',
    ]);

    Http::assertSent(function ($request) {
        expect((string) ($request['parcel-price'] ?? ''))->not->toContain(',');

        return true;
    });
});
