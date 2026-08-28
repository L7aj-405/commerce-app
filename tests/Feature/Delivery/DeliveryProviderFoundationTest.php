<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Services\Orders\DispatchService;
use App\Services\Orders\OrderWorkflowService;

/*
|--------------------------------------------------------------------------
| Delivery provider foundation
|--------------------------------------------------------------------------
*/

it('seeds the internal and ozon delivery providers', function () {
    expect(DeliveryProvider::where('code', 'internal')->exists())->toBeTrue()
        ->and(DeliveryProvider::where('code', 'ozon')->exists())->toBeTrue();
});

it('adds the delivery permission group without disturbing existing keys', function () {
    $keys = PermissionCatalog::keys();

    expect($keys)->toContain('delivery.connections.manage')
        ->toContain('delivery.shipments.create')
        ->toContain('delivery.shipments.track')
        ->toContain('delivery.notes.manage')
        ->toContain('orders.dispatch') // unchanged, still there
        ->toContain('orders.view');
});

it('lists every normalized shipment status the spec requires', function () {
    expect(Shipment::normalizedStatuses())->toBe([
        'draft', 'created', 'sent_to_carrier', 'awaiting_pickup', 'picked_up',
        'in_transit', 'out_for_delivery', 'delivered', 'failed_attempt',
        'returned', 'refused', 'cancelled', 'unknown',
        // Added for strict post-create verification: add-parcel returning a
        // tracking number is not trusted alone — see
        // OzonExpressConnector::verifyShipment().
        'provider_unverified',
    ]);
});

it('leaves the internal manual-courier dispatch flow completely unaffected', function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'fulfillment_status' => FulfillmentStatus::Pending,
        // No product identifier on the line — a genuine custom/service line,
        // never blocks confirmation's unmapped-stock guard.
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);

    $workflow = app(OrderWorkflowService::class);
    foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
        $order = $workflow->transition($order, $s, $owner);
    }

    $shipment = app(DispatchService::class)->assign($order, [
        'carrier_type' => 'internal',
        'agent_id' => $owner->id,
    ], $owner);

    expect($shipment)->toBeInstanceOf(OrderShipment::class)
        ->and($shipment->carrier_type)->toBe('internal')
        ->and(Shipment::where('order_shipment_id', $shipment->id)->exists())->toBeFalse();
});
