<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Orders\DispatchService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Internal delivery agent dashboard
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->product = Product::create([
        'store_id' => $this->store->id, 'name' => 'Hoodie', 'sku' => 'H1',
        'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]);
    Stock::create([
        'product_id' => $this->product->id,
        'warehouse_id' => $this->store->getPrimaryWarehouse()->id, 'quantity' => 50,
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

    $this->driver     = ($this->memberWith)('Delivery agent');
    $this->dispatcher = ($this->memberWith)('Dispatcher');

    // A packed online order dispatched to the driver.
    $this->packAndDispatch = function (User $driver, float $total = 200): OrderShipment {
        $order = Order::factory()->create([
            'store_id' => $this->store->id, 'order_number' => 'ORD-'.fake()->unique()->numerify('####'),
            'fulfillment_status' => FulfillmentStatus::Pending, 'total' => $total,
            'customer_name' => 'Sara', 'customer_phone' => '0600000000',
            'items' => [['name' => 'Hoodie', 'quantity' => 1, 'unit_price' => $total, 'line_total' => $total]],
        ]);
        $workflow = app(OrderWorkflowService::class);
        foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress, FulfillmentStatus::ReadyForDelivery] as $s) {
            $order = $workflow->transition($order, $s, $this->owner);
        }
        return app(DispatchService::class)->assign($order, [
            'carrier_type' => 'internal', 'agent_id' => $driver->id,
        ], $this->dispatcher);
    };
});

describe('roles & access', function () {
    it('seeds a Delivery agent role holding only orders.deliver', function () {
        expect($this->driver->hasStorePermission($this->store, 'orders.deliver'))->toBeTrue()
            ->and($this->driver->hasStorePermission($this->store, 'orders.dispatch'))->toBeFalse()
            ->and($this->driver->hasStorePermission($this->store, 'orders.view'))->toBeFalse();
    });

    it('opens the driver dashboard for a delivery agent', function () {
        ($this->packAndDispatch)($this->driver);

        $this->actingAs($this->driver)
            ->get('/dashboard/my-deliveries')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Delivery/DeliveryAgentView')
                ->has('deliveries', 1)
                ->has('history')
                ->where('agent.name', $this->driver->name),
            );
    });

    it('keeps a driver out of the logistics dispatch board', function () {
        // Confined before the permission check even runs: redirected home, not 403.
        $this->actingAs($this->driver)
            ->get('/dashboard/departments/dispatch')
            ->assertRedirect('/dashboard/my-deliveries');
    });

    it('lists delivery agents (not dispatchers) in the carrier picklist', function () {
        ($this->packAndDispatch)($this->driver); // gives the store an in-flight parcel

        $this->actingAs($this->dispatcher)
            ->get('/dashboard/departments/dispatch')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('agents',
                fn ($agents) => collect($agents)->contains('id', $this->driver->id)
                    && ! collect($agents)->contains('id', $this->dispatcher->id),
            ));
    });
});

describe('confined to the driver interface', function () {
    it('flags a pure driver but never a manager or owner', function () {
        expect($this->driver->isDeliveryOnlyAgent($this->store))->toBeTrue()
            ->and($this->owner->isDeliveryOnlyAgent($this->store))->toBeFalse()
            ->and($this->dispatcher->isDeliveryOnlyAgent($this->store))->toBeFalse();
    });

    it('confines by the assigned role even if it was given extra permissions', function () {
        // An admin tweaks the Delivery agent role to also grant orders.view.
        // The role slug is still the definitive driver signal.
        $role = $this->store->roles()->where('slug', User::DELIVERY_AGENT_ROLE)->firstOrFail();
        $role->update(['permissions' => ['orders.deliver', 'orders.view']]);

        expect($this->driver->fresh()->isDeliveryOnlyAgent($this->store))->toBeTrue();

        $this->actingAs($this->driver)
            ->get('/dashboard')
            ->assertRedirect('/dashboard/my-deliveries');
    });

    it('redirects a driver off the manager dashboard to their own view', function () {
        $this->actingAs($this->driver)
            ->get('/dashboard')
            ->assertRedirect('/dashboard/my-deliveries');
    });

    it('redirects a driver away from any manager page (before the 403)', function () {
        $this->actingAs($this->driver)
            ->get('/dashboard/products')
            ->assertRedirect('/dashboard/my-deliveries');
    });

    it('lets a driver reach their own dashboard', function () {
        $this->actingAs($this->driver)
            ->get('/dashboard/my-deliveries')
            ->assertOk();
    });

    it('does not confine a manager', function () {
        $this->actingAs($this->owner)
            ->get('/dashboard')
            ->assertOk();
    });

    it('sends a delivery driver straight to their view on login', function () {
        $driver = ($this->memberWith)('Delivery agent');

        $this->post('/login', ['email' => $driver->email, 'password' => 'password'])
            ->assertRedirect('/dashboard/my-deliveries');
    });
});

describe('own queue only', function () {
    it('shows a driver only their own parcels', function () {
        $other = ($this->memberWith)('Delivery agent');
        ($this->packAndDispatch)($this->driver);
        ($this->packAndDispatch)($other);

        $this->actingAs($this->driver)
            ->get('/dashboard/my-deliveries')
            ->assertInertia(fn ($p) => $p->has('deliveries', 1));
    });

    it('404s when a driver acts on a parcel that is not theirs', function () {
        $other    = ($this->memberWith)('Delivery agent');
        $shipment = ($this->packAndDispatch)($other);

        $this->actingAs($this->driver)
            ->post("/dashboard/my-deliveries/{$shipment->id}/delivered")
            ->assertNotFound();
    });
});

describe('COD & outcomes', function () {
    it('sets expected COD from the order total at dispatch', function () {
        $shipment = ($this->packAndDispatch)($this->driver, 350);

        expect((float) $shipment->cod_amount)->toBe(350.0);
    });

    it('records collected cash and advances the order on delivery', function () {
        $shipment = ($this->packAndDispatch)($this->driver, 350);

        $this->actingAs($this->driver)
            ->post("/dashboard/my-deliveries/{$shipment->id}/delivered", ['cod_collected' => 300])
            ->assertSessionHas('success');

        $shipment->refresh();
        expect((float) $shipment->cod_collected)->toBe(300.0)
            ->and($shipment->status)->toBe(OrderShipment::STATUS_DELIVERED)
            ->and($shipment->shippable->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Delivered);
    });

    it('reconciles the day: collected vs still-outstanding', function () {
        $a = ($this->packAndDispatch)($this->driver, 200);
        ($this->packAndDispatch)($this->driver, 150);   // stays in the queue

        $this->actingAs($this->driver)
            ->post("/dashboard/my-deliveries/{$a->id}/delivered", ['cod_collected' => 200]);

        $recon = app(DispatchService::class)->agentReconciliation($this->store, $this->driver);

        expect($recon['collected_today'])->toBe(200.0)
            ->and($recon['outstanding'])->toBe(150.0)
            ->and($recon['delivered_today'])->toBe(1)
            ->and($recon['in_queue'])->toBe(1);
    });

    it('moves a closed drop from the queue into the history list', function () {
        $shipment = ($this->packAndDispatch)($this->driver, 200);

        $this->actingAs($this->driver)
            ->post("/dashboard/my-deliveries/{$shipment->id}/delivered", ['cod_collected' => 200]);

        $this->actingAs($this->driver)
            ->get('/dashboard/my-deliveries')
            ->assertInertia(fn ($p) => $p
                ->has('deliveries', 0)          // gone from the live queue
                ->has('history', 1)             // now in history
                ->where('history.0.status', 'delivered')
                ->where('history.0.cod_collected', fn ($v) => (float) $v === 200.0),
            );
    });

    it('pushes a failed delivery into the returns queue without moving stock', function () {
        $shipment = ($this->packAndDispatch)($this->driver, 200);
        $before   = (int) Stock::where('product_id', $this->product->id)->value('quantity');

        $this->actingAs($this->driver)
            ->post("/dashboard/my-deliveries/{$shipment->id}/failed", ['reason' => 'customer_unreachable'])
            ->assertSessionHas('warning');

        expect($shipment->fresh()->status)->toBe(OrderShipment::STATUS_FAILED)
            ->and($shipment->shippable->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Returned)
            ->and(OrderReturn::count())->toBe(1)
            ->and((int) Stock::where('product_id', $this->product->id)->value('quantity'))->toBe($before);
    });

    it('requires a reason to fail a delivery', function () {
        $shipment = ($this->packAndDispatch)($this->driver, 200);

        $this->actingAs($this->driver)
            ->post("/dashboard/my-deliveries/{$shipment->id}/failed", [])
            ->assertSessionHasErrors('reason');
    });
});
