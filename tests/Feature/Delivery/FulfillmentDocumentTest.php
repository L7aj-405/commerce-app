<?php

declare(strict_types=1);

use App\Models\FinanceTransaction;
use App\Models\FulfillmentDocument;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Fulfilment document download — private, tenant-scoped, permission-gated.
|--------------------------------------------------------------------------
*/

function fulfilDocWorkspace(string $name): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function fulfilDocMemberWithRole(Store $store, string $roleName): User
{
    $role = $store->roles()->where('name', $roleName)->firstOrFail();
    // users.role / store_members.role are coarse dashboard-vs-POS gates only
    // (valid values: store_admin|manager|cashier). The real permission set
    // comes from the assigned store_role_id below.
    $user = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $user->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $user;
}

function fulfilDocStoredDocument(Store $store): FulfillmentDocument
{
    $order = Order::factory()->create(['store_id' => $store->id, 'platform_data' => []]);
    $shipment = Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'tracking_number' => 'OZE-DOC-1', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Casablanca',
    ]);

    $path = "fulfillment/{$store->id}/shipments/{$shipment->id}/label.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 stored label');

    return FulfillmentDocument::create([
        'store_id' => $store->id,
        'documentable_type' => $shipment->getMorphClass(), 'documentable_id' => $shipment->id,
        'document_type' => 'carrier_label', 'status' => 'stored', 'provider_code' => 'ozon',
        'disk' => 'local', 'path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => 21,
        'generated_at' => now(),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
});

it('lets an authorized member download a stored fulfilment document', function () {
    [$owner, $store] = fulfilDocWorkspace('FD Store A');
    $document = fulfilDocStoredDocument($store);

    $this->actingAs($owner)
        ->get("/dashboard/fulfillment-documents/{$document->id}/download")
        ->assertOk()
        ->assertDownload("carrier_label-{$document->id}.pdf");
});

it('404s a document that only holds an unfetchable external URL', function () {
    [$owner, $store] = fulfilDocWorkspace('FD Store B');
    $order = Order::factory()->create(['store_id' => $store->id, 'platform_data' => []]);
    $shipment = Shipment::create([
        'store_id' => $store->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'tracking_number' => 'OZE-DOC-2', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Casablanca',
    ]);
    $document = FulfillmentDocument::create([
        'store_id' => $store->id,
        'documentable_type' => $shipment->getMorphClass(), 'documentable_id' => $shipment->id,
        'document_type' => 'carrier_label', 'status' => 'external_url_unavailable', 'provider_code' => 'ozon',
        'disk' => 'local', 'path' => null, 'source_url' => 'https://client.ozonexpress.ma/pdf-delivery-note-tickets?dn-ref=BL-1',
        'generated_at' => now(),
    ]);

    expect($document->is_downloadable)->toBeFalse()
        ->and($document->download_url)->toBeNull();

    $this->actingAs($owner)
        ->get("/dashboard/fulfillment-documents/{$document->id}/download")
        ->assertNotFound();
});

it('never lets another store download a document through the route', function () {
    [, $storeA] = fulfilDocWorkspace('FD Store Owner');
    [$ownerB] = fulfilDocWorkspace('FD Other Store');
    $document = fulfilDocStoredDocument($storeA);

    $this->actingAs($ownerB)
        ->get("/dashboard/fulfillment-documents/{$document->id}/download")
        ->assertNotFound();
});

it('forbids a member without fulfilment.documents.view', function () {
    [, $store] = fulfilDocWorkspace('FD Perm Store');
    $document = fulfilDocStoredDocument($store);
    // Confirmation agent role has no delivery/fulfilment permissions.
    $agent = fulfilDocMemberWithRole($store, 'Confirmation agent');

    $this->actingAs($agent)
        ->get("/dashboard/fulfillment-documents/{$document->id}/download")
        ->assertForbidden();
});

it('lets a warehouse picker download an internal label (view-only permission)', function () {
    [, $store] = fulfilDocWorkspace('FD Warehouse Store');
    $document = fulfilDocStoredDocument($store);
    $picker = fulfilDocMemberWithRole($store, 'Warehouse');

    $this->actingAs($picker)
        ->get("/dashboard/fulfillment-documents/{$document->id}/download")
        ->assertOk();
});

it('creates no finance transaction when a document is downloaded', function () {
    [$owner, $store] = fulfilDocWorkspace('FD Ledger Store');
    $document = fulfilDocStoredDocument($store);

    $before = FinanceTransaction::query()->count();

    $this->actingAs($owner)->get("/dashboard/fulfillment-documents/{$document->id}/download")->assertOk();

    expect(FinanceTransaction::query()->count())->toBe($before);
});
