<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Manual external courier mode — the Dispatch modal's "Manual courier"
| panel posts to the SAME /carrier endpoint the old Assign-carrier dialog
| always used (App\Http\Controllers\Dashboard\DepartmentController::
| assignCarrier() -> DispatchService::assign()), completely unmodified.
| Never calls Ozon/Sendit.
|--------------------------------------------------------------------------
*/

function mcdWorkspace(): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $role = $store->roles()->where('name', 'Dispatcher')->firstOrFail();
    $dispatcher = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $dispatcher->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'fulfillment_status' => FulfillmentStatus::Pending,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Test', 'total' => 250,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 250, 'line_total' => 250]],
    ]);
    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    return [$owner, $store, $dispatcher, $order];
}

it('stores a manual courier shipment with tracking number, tracking URL and manifest reference', function () {
    [, $store, $dispatcher, $order] = mcdWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'courier',
        'carrier_name' => 'Amana',
        'tracking_number' => 'AMN-123',
        'tracking_url' => 'https://amana.example/track/AMN-123',
        'manifest_reference' => 'MAN-AMANA-20260827',
        'notes' => 'Fragile',
    ])->assertRedirect();

    $shipment = OrderShipment::where('store_id', $store->id)->where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->carrier_type)->toBe('courier')
        ->and($shipment->carrier_name)->toBe('Amana')
        ->and($shipment->tracking_number)->toBe('AMN-123')
        ->and($shipment->tracking_url)->toBe('https://amana.example/track/AMN-123')
        ->and($shipment->manifest_reference)->toBe('MAN-AMANA-20260827')
        ->and($shipment->notes)->toBe('Fragile')
        ->and($shipment->status)->toBe(OrderShipment::STATUS_DISPATCHED);

    // Never a rich provider Shipment row — this is exactly what makes the
    // board's provider badge fall back to "Manual courier" (see
    // Dispatch.jsx's dispatchMethodBadge()).
    expect(\App\Models\Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('stores a manual courier shipment with all optional fields left blank', function () {
    [, $store, $dispatcher, $order] = mcdWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'courier',
        'carrier_name' => 'CTM',
    ])->assertRedirect();

    $shipment = OrderShipment::where('store_id', $store->id)->where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->carrier_name)->toBe('CTM')
        ->and($shipment->tracking_number)->toBeNull()
        ->and($shipment->tracking_url)->toBeNull()
        ->and($shipment->manifest_reference)->toBeNull();
});

it('requires a courier name for manual dispatch', function () {
    [, , $dispatcher, $order] = mcdWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'courier',
    ])->assertRedirect()->assertSessionHas('error');

    expect(OrderShipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('never calls Ozon or Sendit for a manual courier assignment', function () {
    [, , $dispatcher, $order] = mcdWorkspace();
    Http::fake();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'courier',
        'carrier_name' => 'Amana',
    ])->assertRedirect();

    Http::assertNothingSent();
});

it('lets a manually-entered "Ozon Express" or "Sendit" name still be saved as a free-text courier — never blocked, just never suggested by the UI', function () {
    [, $store, $dispatcher, $order] = mcdWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'courier',
        'carrier_name' => 'Ozon Express',
    ])->assertRedirect();

    $shipment = OrderShipment::where('store_id', $store->id)->where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->carrier_name)->toBe('Ozon Express')
        // Still no rich provider Shipment row — a free-text "Ozon Express"
        // typed into the manual field is NOT the same as an integrated send.
        ->and(\App\Models\Shipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});
