<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StoreMember;
use App\Models\Store;
use App\Models\User;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Departments, permissions and the returns endpoints (build steps 7-8)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $this->product = Product::create([
        'store_id' => $this->store->id,
        'name'     => 'Blue Hoodie',
        'sku'      => 'BH-L-01',
        'type'     => 'simple',
        'status'   => 'active',
        'price'    => 100,
    ]);
    Stock::create([
        'product_id'   => $this->product->id,
        'warehouse_id' => $this->store->getPrimaryWarehouse()->id,
        'quantity'     => 10,
    ]);

    $this->makeOrder = fn () => Order::factory()->create([
        'store_id'           => $this->store->id,
        'order_number'       => 'ORD-' . fake()->unique()->numerify('####'),
        'fulfillment_status' => FulfillmentStatus::Pending,
        'total'              => 200,
        'items'              => [[
            'product_id' => $this->product->id,
            'name'       => 'Blue Hoodie',
            'quantity'   => 2,
            'unit_price' => 100,
            'line_total' => 200,
        ]],
    ]);

    // A member holding exactly one department role.
    $this->memberWith = function (string $roleName): User {
        $role   = $this->store->roles()->where('name', $roleName)->firstOrFail();
        $member = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);

        StoreMember::create([
            'store_id'      => $this->store->id,
            'user_id'       => $member->id,
            'role'          => 'manager',
            'store_role_id' => $role->id,
            'is_active'     => true,
            'joined_at'     => now(),
        ]);

        return $member;
    };
});

describe('seeded department roles', function () {
    it('seeds the three lifecycle departments alongside the originals', function () {
        $names = $this->store->roles()->pluck('name')->all();

        expect($names)->toContain('Confirmation agent', 'Warehouse', 'Inspector');
    });

    it('grants each department only its own actions', function () {
        $agent     = ($this->memberWith)('Confirmation agent');
        $warehouse = ($this->memberWith)('Warehouse');
        $inspector = ($this->memberWith)('Inspector');

        expect($agent->hasStorePermission($this->store, 'orders.confirm'))->toBeTrue()
            ->and($agent->hasStorePermission($this->store, 'orders.fulfil'))->toBeFalse()
            ->and($agent->hasStorePermission($this->store, 'orders.manage'))->toBeFalse();

        expect($warehouse->hasStorePermission($this->store, 'orders.fulfil'))->toBeTrue()
            ->and($warehouse->hasStorePermission($this->store, 'orders.confirm'))->toBeFalse();

        expect($inspector->hasStorePermission($this->store, 'orders.inspect'))->toBeTrue()
            ->and($inspector->hasStorePermission($this->store, 'orders.confirm'))->toBeFalse();
    });

    it('keeps the existing Manager role working through the coarse permission', function () {
        $manager = ($this->memberWith)('Manager');

        expect($manager->hasStorePermission($this->store, 'orders.manage'))->toBeTrue();
    });
});

describe('status endpoint', function () {
    it('lets the confirmation desk confirm but not fulfil', function () {
        $agent = ($this->memberWith)('Confirmation agent');
        $order = ($this->makeOrder)();

        $this->actingAs($agent)
            ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
            ->assertRedirect();

        expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Confirmed);

        $this->actingAs($agent)
            ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'in_progress'])
            ->assertForbidden();
    });

    it('flashes an error instead of throwing on an illegal move', function () {
        $order = ($this->makeOrder)();

        $this->actingAs($this->owner)
            ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'delivered'])
            ->assertRedirect()
            ->assertSessionHas('error');

        expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
    });

    it('refuses to cancel without a reason', function () {
        $order = ($this->makeOrder)();

        $this->actingAs($this->owner)
            ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('error');

        expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
    });

    it('cancels when a reason is supplied', function () {
        $order = ($this->makeOrder)();

        $this->actingAs($this->owner)
            ->post("/dashboard/orders/online/{$order->id}/status", [
                'status' => 'cancelled',
                'reason' => 'Customer unreachable',
            ])
            ->assertSessionHas('success');

        expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Cancelled);
    });
});

describe('returns endpoints', function () {
    beforeEach(function () {
        $order = ($this->makeOrder)();
        $workflow = app(OrderWorkflowService::class);

        foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress,
                  FulfillmentStatus::ReadyForDelivery, FulfillmentStatus::Delivered,
                  FulfillmentStatus::Completed, FulfillmentStatus::Returned] as $step) {
            $order = $workflow->transition($order, $step, $this->owner, 'refused');
        }

        $this->order  = $order;
        $this->return = OrderReturn::first();
    });

    it('lists open returns for an inspector', function () {
        $inspector = ($this->memberWith)('Inspector');

        $this->actingAs($inspector)
            ->get('/dashboard/orders/returns')
            ->assertOk();
    });

    it('keeps the confirmation desk out of the inspection worksheet', function () {
        $agent = ($this->memberWith)('Confirmation agent');

        $this->actingAs($agent)
            ->get("/dashboard/orders/returns/{$this->return->id}")
            ->assertForbidden();
    });

    it('restocks and closes through the HTTP endpoints', function () {
        $inspector = ($this->memberWith)('Inspector');
        $item      = $this->return->items->first();

        $this->actingAs($inspector)
            ->post("/dashboard/orders/returns/{$this->return->id}/disposition", [
                'lines' => [[
                    'item_id'   => $item->id,
                    'condition' => OrderReturnItem::CONDITION_RESELLABLE,
                    'quantity'  => 2,
                ]],
            ])
            ->assertSessionHas('success');

        $this->actingAs($inspector)
            ->post("/dashboard/orders/returns/{$this->return->id}/close")
            ->assertSessionHas('success');

        expect($this->return->fresh()->status)->toBe(OrderReturn::STATUS_CLOSED)
            ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReturnCompleted)
            ->and((int) Stock::where('product_id', $this->product->id)
                ->where('warehouse_id', $this->store->getPrimaryWarehouse()->id)
                ->value('quantity'))->toBe(10);
    });

    it('refuses to close over the wire while a line is un-inspected', function () {
        $inspector = ($this->memberWith)('Inspector');

        $this->actingAs($inspector)
            ->post("/dashboard/orders/returns/{$this->return->id}/close")
            ->assertSessionHas('error');

        expect($this->return->fresh()->status)->not->toBe(OrderReturn::STATUS_CLOSED);
    });

    it('rejects an unknown condition at validation', function () {
        $inspector = ($this->memberWith)('Inspector');

        $this->actingAs($inspector)
            ->post("/dashboard/orders/returns/{$this->return->id}/disposition", [
                'lines' => [[
                    'item_id'   => $this->return->items->first()->id,
                    'condition' => 'slightly_dented',
                ]],
            ])
            ->assertSessionHasErrors('lines.0.condition');
    });

    it('does not leak returns across stores', function () {
        $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
        $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
        $otherStore->ensureDefaultRoles();

        $this->actingAs($otherOwner)
            ->get("/dashboard/orders/returns/{$this->return->id}")
            ->assertNotFound();
    });
});
