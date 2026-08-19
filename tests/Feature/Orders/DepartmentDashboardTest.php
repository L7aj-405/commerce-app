<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\OrderAssignmentService;
use App\Services\Orders\OrderWorkflowService;
use App\Support\DepartmentRegistry;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Department dashboards: access, assignment and dispatch
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->product = Product::create([
        'store_id' => $this->store->id, 'name' => 'Blue Hoodie', 'sku' => 'BH-1',
        'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]);
    Stock::create([
        'product_id'   => $this->product->id,
        'warehouse_id' => $this->store->getPrimaryWarehouse()->id,
        'quantity'     => 20,
    ]);

    $this->makeOrder = fn (FulfillmentStatus $status = FulfillmentStatus::Pending) => Order::factory()->create([
        'store_id'           => $this->store->id,
        'order_number'       => 'ORD-' . fake()->unique()->numerify('#####'),
        'fulfillment_status' => $status,
        'total'              => 100,
        'items'              => [[
            'product_id' => $this->product->id, 'name' => 'Blue Hoodie',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]],
    ]);

    $this->memberWith = function (string $roleName): User {
        $role   = $this->store->roles()->where('name', $roleName)->firstOrFail();
        $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);

        StoreMember::create([
            'store_id' => $this->store->id, 'user_id' => $member->id, 'role' => 'manager',
            'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
        ]);

        return $member;
    };
});

describe('department access', function () {
    it('shows an owner every department', function () {
        $visible = DepartmentRegistry::visibleTo($this->owner, $this->store);

        expect(array_column($visible, 'key'))
            ->toEqualCanonicalizing(['confirmation', 'packing', 'dispatch', 'returns']);
    });

    it('shows a single-department agent only their own', function () {
        $agent = ($this->memberWith)('Confirmation agent');

        expect(array_column(DepartmentRegistry::visibleTo($agent, $this->store), 'key'))
            ->toBe(['confirmation']);
    });

    it('opens each dashboard for the right role and 403s the others', function () {
        $agent      = ($this->memberWith)('Confirmation agent');
        $packer     = ($this->memberWith)('Warehouse');
        $dispatcher = ($this->memberWith)('Dispatcher');

        $this->actingAs($agent)->get('/dashboard/departments/confirmation')->assertOk();
        $this->actingAs($packer)->get('/dashboard/departments/packing')->assertOk();
        $this->actingAs($dispatcher)->get('/dashboard/departments/dispatch')->assertOk();

        // The pages themselves only need orders.view; the ACTIONS are gated.
        $this->actingAs($agent)
            ->post('/dashboard/departments/take-next/fulfillment')
            ->assertForbidden();
    });
});

describe('assignment', function () {
    it('takes the longest-waiting unassigned order', function () {
        $older = ($this->makeOrder)();
        $older->update(['created_at' => now()->subHour()]);
        $newer = ($this->makeOrder)();

        $this->actingAs($this->owner)
            ->post('/dashboard/departments/take-next/confirmation')
            ->assertSessionHas('success');

        expect($older->fresh()->assigned_to)->toBe($this->owner->id)
            ->and($newer->fresh()->assigned_to)->toBeNull();
    });

    it('warns instead of failing when the queue is empty', function () {
        $this->actingAs($this->owner)
            ->post('/dashboard/departments/take-next/confirmation')
            ->assertSessionHas('warning');
    });

    it('refuses to steal an order another agent already holds', function () {
        $first  = ($this->memberWith)('Confirmation agent');
        $second = ($this->memberWith)('Confirmation agent');
        $order  = ($this->makeOrder)();

        app(OrderAssignmentService::class)->claim($order, $first);

        $this->actingAs($second)
            ->post("/dashboard/departments/online/{$order->id}/claim")
            ->assertSessionHas('error');

        expect($order->fresh()->assigned_to)->toBe($first->id);
    });

    it('puts a released order back in the shared queue', function () {
        $order = ($this->makeOrder)();
        app(OrderAssignmentService::class)->claim($order, $this->owner);

        $this->actingAs($this->owner)
            ->post("/dashboard/departments/online/{$order->id}/release")
            ->assertSessionHas('success');

        expect($order->fresh()->assigned_to)->toBeNull();
    });

    it('reports each agent’s current load', function () {
        $agent = ($this->memberWith)('Confirmation agent');
        app(OrderAssignmentService::class)->claim(($this->makeOrder)(), $agent);

        $workload = app(OrderAssignmentService::class)
            ->workload($this->store, 'orders.confirm', $this->owner, 'confirmation');

        $row = collect($workload)->firstWhere('id', $agent->id);

        expect($row['assigned'])->toBe(1)
            ->and($row['initials'])->not->toBeEmpty()
            // The owner works every queue, so they are listed too.
            ->and(collect($workload)->firstWhere('id', $this->owner->id))->not->toBeNull();
    });
});

describe('dispatch', function () {
    beforeEach(function () {
        $order    = ($this->makeOrder)();
        $workflow = app(OrderWorkflowService::class);

        foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress,
                  FulfillmentStatus::ReadyForDelivery] as $step) {
            $order = $workflow->transition($order, $step, $this->owner);
        }

        $this->order      = $order;
        $this->dispatcher = ($this->memberWith)('Dispatcher');
    });

    it('assigns a third-party courier with tracking', function () {
        $this->actingAs($this->dispatcher)
            ->post("/dashboard/departments/online/{$this->order->id}/carrier", [
                'carrier_type'       => 'courier',
                'carrier_name'       => 'Amana',
                'tracking_number'    => 'AM-4471',
                'manifest_reference' => 'MAN-AMANA-20260724',
            ])
            ->assertSessionHas('success');

        $shipment = OrderShipment::first();

        expect($shipment->carrier_name)->toBe('Amana')
            ->and($shipment->status)->toBe(OrderShipment::STATUS_DISPATCHED)
            ->and($shipment->reference)->toStartWith('SHP-')
            ->and($shipment->carrierLabel())->toBe('Amana');
    });

    it('assigns an internal agent instead', function () {
        $rider = ($this->memberWith)('Dispatcher');

        $this->actingAs($this->dispatcher)
            ->post("/dashboard/departments/online/{$this->order->id}/carrier", [
                'carrier_type' => 'internal',
                'agent_id'     => $rider->id,
            ])
            ->assertSessionHas('success');

        expect(OrderShipment::first()->agent_id)->toBe($rider->id);
    });

    it('rejects a courier with no name and an internal with no agent', function () {
        $this->actingAs($this->dispatcher)
            ->post("/dashboard/departments/online/{$this->order->id}/carrier", ['carrier_type' => 'courier'])
            ->assertSessionHas('error');

        $this->actingAs($this->dispatcher)
            ->post("/dashboard/departments/online/{$this->order->id}/carrier", ['carrier_type' => 'internal'])
            ->assertSessionHas('error');

        expect(OrderShipment::count())->toBe(0);
    });

    it('updates the open shipment rather than creating a second', function () {
        foreach (['Amana', 'CTM'] as $carrier) {
            $this->actingAs($this->dispatcher)
                ->post("/dashboard/departments/online/{$this->order->id}/carrier", [
                    'carrier_type' => 'courier', 'carrier_name' => $carrier,
                ]);
        }

        expect(OrderShipment::count())->toBe(1)
            ->and(OrderShipment::first()->carrier_name)->toBe('CTM');
    });

    it('advances the order when delivery is confirmed', function () {
        $this->actingAs($this->dispatcher)->post("/dashboard/departments/online/{$this->order->id}/carrier", [
            'carrier_type' => 'courier', 'carrier_name' => 'Amana',
        ]);
        $shipment = OrderShipment::first();

        $this->actingAs($this->dispatcher)
            ->post("/dashboard/departments/shipments/{$shipment->id}/delivered")
            ->assertSessionHas('success');

        expect($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Delivered)
            ->and($shipment->fresh()->status)->toBe(OrderShipment::STATUS_DELIVERED)
            ->and($shipment->fresh()->delivered_at)->not->toBeNull();
    });

    it('routes a failed delivery into the returns queue without moving stock', function () {
        $this->actingAs($this->dispatcher)->post("/dashboard/departments/online/{$this->order->id}/carrier", [
            'carrier_type' => 'courier', 'carrier_name' => 'Amana',
        ]);
        $shipment = OrderShipment::first();

        $before = (int) Stock::where('product_id', $this->product->id)->value('quantity');

        $this->actingAs($this->dispatcher)
            ->post("/dashboard/departments/shipments/{$shipment->id}/failed", ['reason' => 'refused'])
            ->assertSessionHas('warning');

        expect($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Returned)
            ->and($shipment->fresh()->status)->toBe(OrderShipment::STATUS_FAILED)
            ->and(\App\Models\OrderReturn::count())->toBe(1)
            // Goods are unverified until an inspector sees them.
            ->and((int) Stock::where('product_id', $this->product->id)->value('quantity'))->toBe($before);
    });

    it('keeps the warehouse out of dispatch actions', function () {
        $packer = ($this->memberWith)('Warehouse');

        $this->actingAs($packer)
            ->post("/dashboard/departments/online/{$this->order->id}/carrier", [
                'carrier_type' => 'courier', 'carrier_name' => 'Amana',
            ])
            ->assertForbidden();
    });

    it('does not leak shipments across stores', function () {
        $this->actingAs($this->dispatcher)->post("/dashboard/departments/online/{$this->order->id}/carrier", [
            'carrier_type' => 'courier', 'carrier_name' => 'Amana',
        ]);
        $shipment = OrderShipment::first();

        $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
        $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
        $otherStore->ensureDefaultRoles();

        $this->actingAs($otherOwner)
            ->post("/dashboard/departments/shipments/{$shipment->id}/delivered")
            ->assertNotFound();
    });
});
