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
| Internal agent mode — the Dispatch modal's "Internal agent" panel posts
| to the SAME /carrier endpoint, carrier_type='internal', unmodified
| DispatchService::assign(). No tracking number, no provider API call.
|--------------------------------------------------------------------------
*/

function iadWorkspace(): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    $dispatcherRole = $store->roles()->where('name', 'Dispatcher')->firstOrFail();
    $dispatcher = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $dispatcher->id, 'role' => 'manager',
        'store_role_id' => $dispatcherRole->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    $agentRole = $store->roles()->where('name', 'Delivery agent')->firstOrFail();
    $agent = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $agent->id, 'role' => 'manager',
        'store_role_id' => $agentRole->id, 'is_active' => true, 'joined_at' => now(),
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

    return [$owner, $store, $dispatcher, $agent, $order];
}

it('stores an internal agent assignment with no tracking number required', function () {
    [, $store, $dispatcher, $agent, $order] = iadWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'internal',
        'agent_id' => $agent->id,
        'notes' => 'Deliver before noon',
    ])->assertRedirect();

    $shipment = OrderShipment::where('store_id', $store->id)->where('shippable_id', $order->id)->firstOrFail();
    expect($shipment->carrier_type)->toBe('internal')
        ->and($shipment->agent_id)->toBe($agent->id)
        ->and($shipment->notes)->toBe('Deliver before noon')
        ->and($shipment->tracking_number)->toBeNull()
        ->and($shipment->status)->toBe(OrderShipment::STATUS_DISPATCHED);
});

it('requires an agent to be chosen for internal dispatch', function () {
    [, , $dispatcher, , $order] = iadWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'internal',
    ])->assertRedirect()->assertSessionHas('error');

    expect(OrderShipment::where('shippable_id', $order->id)->exists())->toBeFalse();
});

it('never calls any provider API for an internal agent assignment', function () {
    [, , $dispatcher, $agent, $order] = iadWorkspace();
    Http::fake();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'internal',
        'agent_id' => $agent->id,
    ])->assertRedirect();

    Http::assertNothingSent();
});

it('advances the driver\'s workload after an internal assignment, the same as before this refactor', function () {
    [, , $dispatcher, $agent, $order] = iadWorkspace();

    $this->actingAs($dispatcher)->post("/dashboard/departments/online/{$order->id}/carrier", [
        'carrier_type' => 'internal',
        'agent_id' => $agent->id,
    ])->assertRedirect();

    $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.shipment.carrier_type', 'internal')
            ->where('orders.0.shipment.carrier_label', $agent->name));
});
