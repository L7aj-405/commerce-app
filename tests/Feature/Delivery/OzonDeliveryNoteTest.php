<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryNote;
use App\Models\DeliveryProviderCity;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\DeliveryNoteService;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Ozon delivery note (Bon de Livraison) flow
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $role = $this->store->roles()->where('name', 'Manager')->firstOrFail();
    $this->manager = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $this->manager->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->connection = DeliveryConnection::create([
        'store_id' => $this->store->id, 'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca']);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Ozon', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $this->owner);
    }

    Http::fake(['api.ozonexpress.ma/*/add-parcel' => Http::response(['TRACKING-NUMBER' => 'OZE1'], 200)]);
    $this->shipment = app(OzonShipmentService::class)->send($order, $this->connection, [], $this->owner);
});

it('creates a delivery note', function () {
    Http::fake(['api.ozonexpress.ma/*/add-delivery-note' => Http::response(['ok' => true], 200)]);

    $note = app(DeliveryNoteService::class)->create($this->connection);

    expect($note->provider_ref)->not->toBeNull()
        ->and($note->status)->toBe(DeliveryNote::STATUS_DRAFT);
});

it('adds parcels to a delivery note', function () {
    Http::fake([
        'api.ozonexpress.ma/*/add-delivery-note' => Http::response(['ok' => true], 200),
        'api.ozonexpress.ma/*/add-parcel-to-delivery-note' => Http::response(['ok' => true], 200),
    ]);

    $note = app(DeliveryNoteService::class)->create($this->connection);
    app(DeliveryNoteService::class)->addShipments($note, Shipment::whereKey($this->shipment->id)->get());

    expect($note->shipments()->count())->toBe(1)
        ->and($this->shipment->fresh()->delivery_note_ref)->toBe($note->provider_ref);
});

it('saves a delivery note and stores the pdf/label links', function () {
    Http::fake([
        'api.ozonexpress.ma/*/add-delivery-note' => Http::response(['ok' => true], 200),
        'api.ozonexpress.ma/*/save-delivery-note' => Http::response(['ok' => true], 200),
    ]);

    $note = app(DeliveryNoteService::class)->create($this->connection);
    $saved = app(DeliveryNoteService::class)->save($note);

    expect($saved->status)->toBe(DeliveryNote::STATUS_SAVED)
        ->and($saved->pdf_url)->toContain($note->provider_ref)
        ->and($saved->labels_pdf_url)->toContain($note->provider_ref)
        ->and($saved->saved_at)->not->toBeNull();
});

it('creates a delivery note over HTTP, scoped to the active store', function () {
    Http::fake(['api.ozonexpress.ma/*/add-delivery-note' => Http::response(['ok' => true], 200)]);

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-notes/ozon')
        ->assertRedirect();

    expect(DeliveryNote::where('store_id', $this->store->id)->count())->toBe(1);
});
