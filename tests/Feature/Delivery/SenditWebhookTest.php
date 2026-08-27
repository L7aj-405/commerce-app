<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\SenditShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sendit webhook receiver — signature verification, idempotency, and status
| application through the SAME ShipmentTrackingService::apply() the polling
| path uses (never a parallel status writer).
|--------------------------------------------------------------------------
*/

function senditWebhookSign(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB1', 'secret_key' => 'webhook-secret'],
        'settings' => ['default_pickup_district_id' => '1'], 'status' => DeliveryConnection::STATUS_CONNECTED,
        'created_by' => $this->owner->id,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'sendit', 'provider_city_id' => '12', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'sendit', 'delivery_provider_city_id' => $providerCity->id]);

    $order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Sendit', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $this->owner);
    }
    $this->order = $order;

    Http::fake([
        'app.sendit.ma/api/v1/login' => Http::response(['token' => 'tok_wh'], 200),
        'app.sendit.ma/api/v1/deliveries' => Http::response(['success' => true, 'data' => ['code' => 'SND-WH-1']], 200),
    ]);
    $this->shipment = app(SenditShipmentService::class)->send($this->order, $this->connection, [], $this->owner);

    $this->webhookUrl = "/api/webhooks/sendit/{$this->connection->id}";
});

function senditWebhookPayload(string $code, string $oldStatus, string $newStatus, array $extra = []): array
{
    return array_merge([
        'event' => 'delivery.status.update',
        'code' => $code,
        'oldStatus' => $oldStatus,
        'newStatus' => $newStatus,
        'lastActionAt' => now()->toIso8601String(),
        'message' => null,
        'proofImage' => null,
        'deliverBy' => null,
        'counterUnreachable' => 0,
    ], $extra);
}

it('verifies the X-Sendit-Signature before processing', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');
    $body = json_encode($payload);
    $signature = senditWebhookSign($body, 'webhook-secret');

    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => $signature])
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_DELIVERED);
});

it('rejects a webhook with an invalid signature and never applies it', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');

    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => 'not-the-real-signature'])
        ->assertStatus(401);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER);
});

it('rejects a webhook with a missing signature header', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');

    $this->postJson($this->webhookUrl, $payload)->assertStatus(401);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER);
});

it('is idempotent — a duplicate webhook for the same event does not duplicate events or transitions', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');
    $body = json_encode($payload);
    $signature = senditWebhookSign($body, 'webhook-secret');

    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => $signature])->assertOk();
    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => $signature])->assertOk();

    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1)
        ->and($this->shipment->fresh()->status)->toBe(Shipment::STATUS_DELIVERED);

    $orderShipment = OrderShipment::findOrFail($this->shipment->order_shipment_id);
    // markDelivered() is only ever invoked once — a second identical call
    // would be a no-op in DispatchService anyway (isClosed() guard in
    // ShipmentTrackingService::closeOutOrderShipment), but the important
    // assertion here is that the event count stayed at 1.
    expect($orderShipment->status)->toBe(OrderShipment::STATUS_DELIVERED);
});

it('updates shipment and order correctly when newStatus is DELIVERED', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');
    $body = json_encode($payload);
    $signature = senditWebhookSign($body, 'webhook-secret');

    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => $signature])->assertOk();

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Delivered);

    $event = ShipmentEvent::where('shipment_id', $this->shipment->id)->latest('created_at')->firstOrFail();
    expect($event->normalized_status)->toBe(Shipment::STATUS_DELIVERED)
        ->and($event->provider_status)->toBe('DELIVERED')
        ->and($event->raw_payload['event'])->toBe('delivery.status.update');
});

it('stores proofImage/deliverBy/counterUnreachable in the event payload for later display', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'DISTRIBUTED', 'UNREACHABLE', [
        'proofImage' => 'https://cdn.sendit.ma/proof/abc.jpg',
        'deliverBy' => now()->addDay()->toIso8601String(),
        'counterUnreachable' => 2,
        'message' => 'Customer did not answer.',
    ]);
    $body = json_encode($payload);
    $signature = senditWebhookSign($body, 'webhook-secret');

    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => $signature])->assertOk();

    $event = ShipmentEvent::where('shipment_id', $this->shipment->id)->latest('created_at')->firstOrFail();
    expect($event->raw_payload['proofImage'])->toBe('https://cdn.sendit.ma/proof/abc.jpg')
        ->and($event->raw_payload['counterUnreachable'])->toBe(2)
        ->and($event->raw_payload['message'])->toBe('Customer did not answer.')
        ->and($event->normalized_status)->toBe(Shipment::STATUS_FAILED_ATTEMPT);
});

it('logs a safe warning and does not error for an unknown delivery code', function () {
    \Illuminate\Support\Facades\Log::spy();

    $payload = senditWebhookPayload('SND-DOES-NOT-EXIST', 'TRANSIT', 'DELIVERED');
    $body = json_encode($payload);
    $signature = senditWebhookSign($body, 'webhook-secret');

    $this->postJson($this->webhookUrl, $payload, ['X-Sendit-Signature' => $signature])
        ->assertOk()
        ->assertJson(['status' => 'ignored']);

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'no matching shipment'));
});

it('404s for an unknown connection id', function () {
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');

    $this->postJson('/api/webhooks/sendit/not-a-real-connection-id', $payload, [
        'X-Sendit-Signature' => senditWebhookSign(json_encode($payload), 'webhook-secret'),
    ])->assertStatus(404);
});

it('never applies a webhook meant for another store\'s connection to this shipment', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();
    $otherConnection = DeliveryConnection::create([
        'store_id' => $otherStore->id, 'provider_code' => 'sendit', 'name' => 'Sendit',
        'credentials' => ['public_key' => 'PUB2', 'secret_key' => 'other-secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
        'created_by' => $otherOwner->id,
    ]);

    // Same delivery code, but signed with the OTHER store's connection and
    // posted to the OTHER store's webhook URL — must never touch this
    // store's shipment (store_id is part of the shipment lookup).
    $payload = senditWebhookPayload('SND-WH-1', 'TRANSIT', 'DELIVERED');
    $body = json_encode($payload);
    $signature = senditWebhookSign($body, 'other-secret');

    $this->postJson("/api/webhooks/sendit/{$otherConnection->id}", $payload, ['X-Sendit-Signature' => $signature])
        ->assertOk()
        ->assertJson(['status' => 'ignored']);

    expect($this->shipment->fresh()->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER);
});
