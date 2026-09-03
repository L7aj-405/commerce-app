<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteShipment;
use App\Models\DeliveryProviderCity;
use App\Models\FinanceTransaction;
use App\Models\FulfillmentDocument;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Delivery\DeliveryNoteService;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Ozon Bon de Livraison — one-click BL + carrier label PDF capture.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('local');

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
    $providerCity = DeliveryProviderCity::create(['store_id' => $this->store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17', 'city_name' => 'Casablanca', 'delivered_price' => 22]);
    CityDeliveryProviderMapping::create(['store_id' => $this->store->id, 'city_id' => $city->id, 'provider_code' => 'ozon', 'delivery_provider_city_id' => $providerCity->id]);

    $order = Order::factory()->create([
        'store_id' => $this->store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Ozon', 'shipping_city_id' => $city->id, 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
        'platform_data' => [],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $this->owner);
    }

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['ADD-PARCEL' => ['RESULT' => 'SUCCESS', 'NEW-PARCEL' => ['TRACKING-NUMBER' => 'OZE1']]], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['PARCEL-INFO' => ['RESULT' => 'SUCCESS']], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response(['TRACKING' => ['RESULT' => 'SUCCESS', 'status' => 'Nouveau colis']], 200),
    ]);

    $this->shipment = app(OzonShipmentService::class)->send($order, $this->connection, [], $this->owner);
    expect($this->shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER);
});

function bllFakeOzon(array $client): void
{
    Http::fake(array_merge([
        'api.ozonexpress.ma/*/add-delivery-note' => Http::response(['ok' => true], 200),
        'api.ozonexpress.ma/*/add-parcel-to-delivery-note' => Http::response(['ok' => true], 200),
        'api.ozonexpress.ma/*/save-delivery-note' => Http::response(['ok' => true], 200),
    ], $client));
}

it('creates and saves an Ozon BL for selected shipments and stores the label PDFs privately', function () {
    bllFakeOzon(['client.ozonexpress.ma/*' => Http::response('%PDF-1.4 fake-label', 200, ['Content-Type' => 'application/pdf'])]);

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-notes/ozon/generate-labels', ['shipment_ids' => [$this->shipment->id]])
        ->assertRedirect()
        ->assertSessionHas('success');

    $note = DeliveryNote::where('store_id', $this->store->id)->where('provider_code', 'ozon')->sole();
    expect($note->status)->toBe(DeliveryNote::STATUS_SAVED)
        ->and($note->pdf_url)->toContain($note->provider_ref)
        ->and($note->labels_pdf_url)->toContain($note->provider_ref)
        ->and($this->shipment->fresh()->delivery_note_ref)->toBe($note->provider_ref);

    // Parcel added to the BL by tracking number, exactly once.
    expect(DeliveryNoteShipment::where('delivery_note_id', $note->id)->count())->toBe(1);

    $blDoc = FulfillmentDocument::where('documentable_type', $note->getMorphClass())
        ->where('documentable_id', $note->id)->where('document_type', 'delivery_note')->sole();
    $labelDoc = FulfillmentDocument::where('documentable_type', $note->getMorphClass())
        ->where('documentable_id', $note->id)->where('document_type', 'carrier_label')
        ->where('provider_code', 'ozon')->sole();

    expect($blDoc->status->value)->toBe('stored')
        ->and($labelDoc->status->value)->toBe('stored')
        ->and(Storage::disk('local')->exists($blDoc->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($labelDoc->path))->toBeTrue()
        // Never a fallback label when the official PDF came through.
        ->and(FulfillmentDocument::where('document_type', 'fallback_label')->count())->toBe(0);
});

it('tries the 4-4 and 4A3 ticket-sheet paths and stores whichever returns a PDF', function () {
    bllFakeOzon(['client.ozonexpress.ma/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-notes/ozon/generate-labels', ['shipment_ids' => [$this->shipment->id]])
        ->assertRedirect();

    $fourUp = FulfillmentDocument::where('document_type', 'carrier_label')->where('provider_code', 'ozon-4up')->first();

    expect($fourUp)->not->toBeNull()
        ->and($fourUp->status->value)->toBe('stored')
        ->and($fourUp->metadata['variant'])->toBe('4up')
        ->and($fourUp->metadata['tried_url'])->toContain('pdf-delivery-note-tickets-4-4');
});

it('does not 500 when the Ozon PDF host needs a session, and generates an internal fallback label instead', function () {
    bllFakeOzon(['client.ozonexpress.ma/*' => Http::response('<!DOCTYPE html><html><body>Please log in</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-notes/ozon/generate-labels', ['shipment_ids' => [$this->shipment->id]])
        ->assertRedirect()
        ->assertSessionHas('warning');

    $note = DeliveryNote::where('store_id', $this->store->id)->sole();

    $labelDoc = FulfillmentDocument::where('documentable_id', $note->id)
        ->where('document_type', 'carrier_label')->where('provider_code', 'ozon')->sole();
    expect($labelDoc->status->value)->toBe('external_url_unavailable')
        ->and($labelDoc->path)->toBeNull()
        ->and($labelDoc->source_url)->toContain('pdf-delivery-note-tickets');

    $fallback = FulfillmentDocument::where('document_type', 'fallback_label')->sole();
    expect($fallback->status->value)->toBe('generated')
        ->and($fallback->documentable_type)->toBe($this->shipment->getMorphClass())
        ->and($fallback->documentable_id)->toBe($this->shipment->id)
        ->and(Storage::disk('local')->exists($fallback->path))->toBeTrue()
        ->and($fallback->is_downloadable)->toBeTrue();
});

it('never adds the same shipment to one BL twice', function () {
    bllFakeOzon(['client.ozonexpress.ma/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);

    $notes = app(DeliveryNoteService::class);
    $note = $notes->create($this->connection);
    $shipments = Shipment::whereKey($this->shipment->id)->get();

    $notes->addShipments($note, $shipments);
    $notes->addShipments($note, $shipments);

    expect(DeliveryNoteShipment::where('delivery_note_id', $note->id)->count())->toBe(1);
});

it('creates no finance transaction when generating labels', function () {
    bllFakeOzon(['client.ozonexpress.ma/*' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $before = FinanceTransaction::query()->count();

    $this->actingAs($this->manager)
        ->post('/dashboard/delivery-notes/ozon/generate-labels', ['shipment_ids' => [$this->shipment->id]])
        ->assertRedirect();

    expect(FinanceTransaction::query()->count())->toBe($before);
});

it('rejects generating labels without the fulfilment.documents.print permission', function () {
    $viewerRole = $this->store->roles()->where('name', 'Viewer')->firstOrFail();
    // users.role / store_members.role are coarse dashboard-vs-POS gates
    // (store_admin|manager|cashier only); the Viewer store_role_id below is
    // what actually withholds delivery.notes.manage / fulfillment.documents.print.
    $viewer = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $this->store->id, 'user_id' => $viewer->id, 'role' => 'manager',
        'store_role_id' => $viewerRole->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->post('/dashboard/delivery-notes/ozon/generate-labels', ['shipment_ids' => [$this->shipment->id]])
        ->assertForbidden();

    expect(DeliveryNote::count())->toBe(0);
});
