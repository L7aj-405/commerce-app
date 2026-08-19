<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Jobs\SyncInventoryToWebhooks;
use App\Models\InventoryAdjustment;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Orders\ReturnInspectionService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Order workflow + return inspection (build steps 5-6)
|--------------------------------------------------------------------------
| Proves the stock transition matrix from docs/order-lifecycle.md.
*/

beforeEach(function () {
    Queue::fake();

    $this->actor = User::factory()->create();
    $this->store = Store::factory()->create(['user_id' => $this->actor->id]);

    $this->primary = $this->store->getPrimaryWarehouse();
    $this->damaged = $this->store->getDamagedWarehouse();

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
        'warehouse_id' => $this->primary->id,
        'quantity'     => 10,
    ]);

    $this->workflow = app(OrderWorkflowService::class);

    $this->makeOrder = fn (FulfillmentStatus $status = FulfillmentStatus::Pending) => Order::factory()->create([
        'store_id'           => $this->store->id,
        'order_number'       => 'ORD-' . fake()->unique()->numerify('####'),
        'fulfillment_status' => $status,
        'total'              => 300,
        'items'              => [[
            'product_id' => $this->product->id,
            'name'       => 'Blue Hoodie',
            'sku'        => 'BH-L-01',
            'quantity'   => 3,
            'unit_price' => 100,
            'line_total' => 300,
        ]],
    ]);

    $this->sellable = fn () => (int) Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->primary->id)
        ->value('quantity');

    $this->damagedQty = fn () => (int) Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->damaged->id)
        ->value('quantity');
});

describe('stock commitment', function () {
    it('deducts stock when an online order is confirmed', function () {
        $order = ($this->makeOrder)();

        $this->workflow->transition($order, FulfillmentStatus::Confirmed, $this->actor);

        expect(($this->sellable)())->toBe(7)
            ->and(StockLedger::where('type', 'sale')->sum('quantity_change'))->toBe(-3);
    });

    it('does not move stock again on the rest of the forward path', function () {
        $order = ($this->makeOrder)();

        foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress,
                  FulfillmentStatus::ReadyForDelivery, FulfillmentStatus::Delivered,
                  FulfillmentStatus::Completed] as $step) {
            $order = $this->workflow->transition($order, $step, $this->actor);
        }

        expect(($this->sellable)())->toBe(7)
            ->and(StockLedger::count())->toBe(1);
    });

    it('releases stock when a confirmed order is cancelled', function () {
        $order = ($this->makeOrder)();
        $order = $this->workflow->transition($order, FulfillmentStatus::Confirmed, $this->actor);

        $this->workflow->transition($order, FulfillmentStatus::Cancelled, $this->actor, 'out of stock');

        expect(($this->sellable)())->toBe(10)
            ->and(StockLedger::where('type', 'adjustment')->sum('quantity_change'))->toBe(3);
    });

    it('moves no stock when an unconfirmed order is cancelled', function () {
        $order = ($this->makeOrder)();

        $this->workflow->transition($order, FulfillmentStatus::Cancelled, $this->actor, 'customer changed mind');

        expect(($this->sellable)())->toBe(10)
            ->and(StockLedger::count())->toBe(0);
    });

    it('queues a storefront sync for each sellable movement', function () {
        $order = ($this->makeOrder)();

        $this->workflow->transition($order, FulfillmentStatus::Confirmed, $this->actor);

        Queue::assertPushed(SyncInventoryToWebhooks::class, 1);
        expect(InventoryAdjustment::where('sync_status', 'pending')->count())->toBe(1);
    });
});

describe('transition guards', function () {
    it('rejects an illegal edge', function () {
        $order = ($this->makeOrder)();

        expect(fn () => $this->workflow->transition($order, FulfillmentStatus::Delivered, $this->actor))
            ->toThrow(ValidationException::class);

        expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending)
            ->and(($this->sellable)())->toBe(10);
    });

    it('requires a reason to cancel or to flag a return', function () {
        $order = ($this->makeOrder)();

        expect(fn () => $this->workflow->transition($order, FulfillmentStatus::Cancelled, $this->actor))
            ->toThrow(ValidationException::class);
    });

    it('projects the legacy commercial status', function () {
        $order = ($this->makeOrder)();

        $order = $this->workflow->transition($order, FulfillmentStatus::Confirmed, $this->actor);
        expect($order->status)->toBe(OrderStatus::Confirmed);

        $order = $this->workflow->transition($order, FulfillmentStatus::InProgress, $this->actor);
        expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

        $order = $this->workflow->transition($order, FulfillmentStatus::ReadyForDelivery, $this->actor);
        $order = $this->workflow->transition($order, FulfillmentStatus::Delivered, $this->actor);
        $order = $this->workflow->transition($order, FulfillmentStatus::Completed, $this->actor);
        expect($order->fresh()->status)->toBe(OrderStatus::Completed);
    });

    it('confirms through the workflow when a customer replies on WhatsApp', function () {
        $order = ($this->makeOrder)();

        $order->markAsConfirmed();
        $order->refresh();

        expect($order->fulfillment_status)->toBe(FulfillmentStatus::Confirmed)
            ->and($order->status)->toBe(OrderStatus::Confirmed)
            ->and($order->whatsapp_confirmed_at)->not->toBeNull()
            // The confirmation queue drains itself: stock is committed too.
            ->and(($this->sellable)())->toBe(7);
    });
});

describe('returns', function () {
    beforeEach(function () {
        $order = ($this->makeOrder)();
        foreach ([FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress,
                  FulfillmentStatus::ReadyForDelivery, FulfillmentStatus::Delivered,
                  FulfillmentStatus::Completed] as $step) {
            $order = $this->workflow->transition($order, $step, $this->actor);
        }

        $this->order       = $order;
        $this->inspections = app(ReturnInspectionService::class);
    });

    it('moves no stock when an order is flagged as returned', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'refused');

        $return = OrderReturn::first();

        expect(($this->sellable)())->toBe(7)          // unchanged from the sale
            ->and(($this->damagedQty)())->toBe(0)
            ->and($return)->not->toBeNull()
            ->and($return->status)->toBe(OrderReturn::STATUS_AWAITING_INSPECTION)
            ->and($return->reference)->toStartWith('RET-')
            ->and($return->items)->toHaveCount(1)
            ->and($return->items->first()->condition)->toBeNull();
    });

    it('restocks resellable goods into active inventory', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'customer_remorse');
        $return = OrderReturn::first();

        $this->inspections->disposition($return, [[
            'item_id'   => $return->items->first()->id,
            'condition' => OrderReturnItem::CONDITION_RESELLABLE,
            'quantity'  => 3,
        ]], $this->actor);

        expect(($this->sellable)())->toBe(10)
            ->and(($this->damagedQty)())->toBe(0)
            ->and(StockLedger::where('type', 'return')->sum('quantity_change'))->toBe(3)
            // Sellable again, so the storefront has to be told.
            ->and(InventoryAdjustment::where('source', 'return_restock')->count())->toBe(1);
    });

    it('writes damaged goods off to the damaged warehouse and tells no storefront', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'damaged_in_transit');
        $return = OrderReturn::first();

        $this->inspections->disposition($return, [[
            'item_id'   => $return->items->first()->id,
            'condition' => OrderReturnItem::CONDITION_DAMAGED,
            'quantity'  => 3,
        ]], $this->actor);

        expect(($this->sellable)())->toBe(7)
            ->and(($this->damagedQty)())->toBe(3)
            ->and(StockLedger::where('type', 'damage')->sum('quantity_change'))->toBe(3)
            ->and(InventoryAdjustment::where('source', 'return_restock')->count())->toBe(0);

        // The written-off units never count as on hand.
        $product = Product::query()->withSellableStock()->find($this->product->id);
        expect((int) $product->total_stock)->toBe(7)
            ->and((int) $product->damaged_stock)->toBe(3);
    });

    it('moves no stock for goods that never arrived', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'other');
        $return = OrderReturn::first();

        $this->inspections->disposition($return, [[
            'item_id'   => $return->items->first()->id,
            'condition' => OrderReturnItem::CONDITION_MISSING,
        ]], $this->actor);

        expect(($this->sellable)())->toBe(7)
            ->and(($this->damagedQty)())->toBe(0)
            ->and($return->items->first()->fresh()->isDispositioned())->toBeTrue();
    });

    it('restocks once when the same inspection is submitted twice', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'refused');
        $return = OrderReturn::first();

        $lines = [[
            'item_id'   => $return->items->first()->id,
            'condition' => OrderReturnItem::CONDITION_RESELLABLE,
            'quantity'  => 3,
        ]];

        $this->inspections->disposition($return, $lines, $this->actor);
        $this->inspections->disposition($return, $lines, $this->actor);

        expect(($this->sellable)())->toBe(10)
            ->and(StockLedger::where('type', 'return')->count())->toBe(1);
    });

    it('refuses to close while a line is still un-inspected', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'refused');
        $return = OrderReturn::first();

        expect(fn () => $this->inspections->close($return, $this->actor))
            ->toThrow(ValidationException::class);

        expect($return->fresh()->status)->not->toBe(OrderReturn::STATUS_CLOSED);
    });

    it('closes the return and finishes the order once every line is dispositioned', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'refused');
        $return = OrderReturn::first();

        $this->inspections->disposition($return, [[
            'item_id'   => $return->items->first()->id,
            'condition' => OrderReturnItem::CONDITION_RESELLABLE,
        ]], $this->actor);

        // Inspecting a line moves the order onto the inspection bench.
        expect($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::UnderInspection);

        $this->inspections->close($return, $this->actor);

        expect($return->fresh()->status)->toBe(OrderReturn::STATUS_CLOSED)
            ->and($this->order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReturnCompleted)
            // The sale still happened — a return must not reopen it commercially.
            ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);
    });

    it('reuses the open return rather than opening a second', function () {
        $this->workflow->transition($this->order, FulfillmentStatus::Returned, $this->actor, 'refused');

        $inspections = $this->inspections;
        $inspections->open($this->order, 'refused', $this->actor);

        expect(OrderReturn::count())->toBe(1);
    });
});

describe('online line item id resolution', function () {
    // Regression: a synced online order stores the PLATFORM product id (e.g. a
    // WooCommerce id like 7778), not our local ULID. Confirming it must resolve
    // that id to the local product before moving stock — otherwise the FK on
    // stocks.product_id blows up with an integrity-constraint violation.
    it('resolves a platform external_id to the local product and deducts stock', function () {
        $external = Product::create([
            'store_id'    => $this->store->id,
            'name'        => 'Synced Tee',
            'sku'         => 'SYNC-7778',
            'type'        => 'simple',
            'status'      => 'active',
            'price'       => 50,
            'platform'    => 'woocommerce',
            'external_id' => '7778',
        ]);

        Stock::create([
            'product_id'   => $external->id,
            'warehouse_id' => $this->primary->id,
            'quantity'     => 10,
        ]);

        $order = Order::factory()->create([
            'store_id'           => $this->store->id,
            'order_number'       => 'ORD-' . fake()->unique()->numerify('####'),
            'fulfillment_status' => FulfillmentStatus::Pending,
            'total'              => 100,
            'items'              => [[
                'product_id' => '7778', // platform id, as the connector stores it
                'name'       => 'Synced Tee',
                'sku'        => 'SYNC-7778',
                'quantity'   => 2,
                'unit_price' => 50,
                'line_total' => 100,
            ]],
        ]);

        $this->workflow->transition($order, FulfillmentStatus::Confirmed, $this->actor);

        $remaining = (int) Stock::query()
            ->where('product_id', $external->id)
            ->where('warehouse_id', $this->primary->id)
            ->value('quantity');

        expect($remaining)->toBe(8)
            ->and(
                StockLedger::where('product_id', $external->id)->where('type', 'sale')->sum('quantity_change')
            )->toBe(-2);
    });

    it('moves no stock for an online line that matches no local product', function () {
        $order = Order::factory()->create([
            'store_id'           => $this->store->id,
            'order_number'       => 'ORD-' . fake()->unique()->numerify('####'),
            'fulfillment_status' => FulfillmentStatus::Pending,
            'total'              => 100,
            'items'              => [[
                'product_id' => '999999', // not stocked locally
                'name'       => 'Ghost Item',
                'sku'        => 'GHOST',
                'quantity'   => 2,
                'unit_price' => 50,
                'line_total' => 100,
            ]],
        ]);

        // Must not throw — the unmatched line resolves to null and moves nothing.
        $this->workflow->transition($order, FulfillmentStatus::Confirmed, $this->actor);

        expect(StockLedger::count())->toBe(0)
            ->and($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Confirmed);
    });
});
