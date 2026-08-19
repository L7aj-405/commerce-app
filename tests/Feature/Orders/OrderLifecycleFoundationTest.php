<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Warehouse;

/*
|--------------------------------------------------------------------------
| Order lifecycle foundation (build steps 1-4)
|--------------------------------------------------------------------------
| Covers the state machine graph, the column type that carries it, and the
| damaged-stock scoping. The workflow service and returns UI are not built yet.
*/

describe('state machine', function () {
    it('routes the happy path from confirmation through to completion', function () {
        $path = [
            FulfillmentStatus::Pending,
            FulfillmentStatus::Confirmed,
            FulfillmentStatus::InProgress,
            FulfillmentStatus::ReadyForDelivery,
            FulfillmentStatus::Delivered,
            FulfillmentStatus::Completed,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            [$from, $to] = [$path[$i], $path[$i + 1]];
            expect($from->canTransitionTo($to))->toBeTrue("{$from->value} → {$to->value}");
        }
    });

    it('walks the full return flow from a completed order', function () {
        expect(FulfillmentStatus::Completed->canTransitionTo(FulfillmentStatus::Returned))->toBeTrue()
            ->and(FulfillmentStatus::Returned->canTransitionTo(FulfillmentStatus::UnderInspection))->toBeTrue()
            ->and(FulfillmentStatus::UnderInspection->canTransitionTo(FulfillmentStatus::ReturnCompleted))->toBeTrue()
            ->and(FulfillmentStatus::ReturnCompleted->isTerminal())->toBeTrue();
    });

    it('rejects skipping the confirmation desk', function () {
        expect(FulfillmentStatus::Pending->canTransitionTo(FulfillmentStatus::Delivered))->toBeFalse()
            ->and(FulfillmentStatus::Pending->canTransitionTo(FulfillmentStatus::Completed))->toBeFalse()
            ->and(FulfillmentStatus::Pending->canTransitionTo(FulfillmentStatus::InProgress))->toBeFalse();
    });

    it('offers a return, not a cancellation, once the goods are dispatched', function () {
        expect(FulfillmentStatus::Delivered->canTransitionTo(FulfillmentStatus::Cancelled))->toBeFalse()
            ->and(FulfillmentStatus::Completed->canTransitionTo(FulfillmentStatus::Cancelled))->toBeFalse()
            ->and(FulfillmentStatus::ReadyForDelivery->canTransitionTo(FulfillmentStatus::Returned))->toBeTrue();
    });

    it('treats cancelled and return_completed as the only dead ends', function () {
        $terminal = array_filter(FulfillmentStatus::cases(), fn ($s) => $s->isTerminal());

        expect($terminal)->toEqualCanonicalizing([
            FulfillmentStatus::Cancelled,
            FulfillmentStatus::ReturnCompleted,
        ]);
    });

    it('demands a reason only where value is destroyed or diverted', function () {
        expect(FulfillmentStatus::Cancelled->requiresReason())->toBeTrue()
            ->and(FulfillmentStatus::Returned->requiresReason())->toBeTrue()
            ->and(FulfillmentStatus::Confirmed->requiresReason())->toBeFalse();
    });

    it('lets each department cancel within its own phase', function () {
        expect(FulfillmentStatus::Cancelled->permission(FulfillmentStatus::Pending))->toBe('orders.confirm')
            ->and(FulfillmentStatus::Cancelled->permission(FulfillmentStatus::InProgress))->toBe('orders.fulfil')
            ->and(FulfillmentStatus::ReturnCompleted->permission())->toBe('orders.inspect');
    });

    it('groups every state into exactly one department phase', function () {
        foreach (FulfillmentStatus::cases() as $status) {
            expect($status->phase())->toBeIn(['confirmation', 'fulfillment', 'delivery', 'closed', 'returns']);
        }
    });

    it('hands an order to logistics once it is packed', function () {
        // Packing ends at ReadyForDelivery; from there it is the dispatch board's.
        expect(FulfillmentStatus::InProgress->phase())->toBe('fulfillment')
            ->and(FulfillmentStatus::ReadyForDelivery->phase())->toBe('delivery')
            ->and(FulfillmentStatus::Delivered->phase())->toBe('delivery')
            ->and(FulfillmentStatus::Delivered->permission())->toBe('orders.dispatch')
            ->and(FulfillmentStatus::ReadyForDelivery->permission())->toBe('orders.fulfil');
    });
});

describe('fulfillment_status column', function () {
    // The column was originally a native ENUM over five states. MySQL truncates
    // values outside an ENUM (error 1265) and SQLite compiles one to a CHECK
    // constraint, so this guards the migration that widened it.
    it('persists every state, including the ones added after the column was created', function () {
        $store = Store::factory()->create();

        foreach (FulfillmentStatus::cases() as $status) {
            $order = Order::factory()->create([
                'store_id'           => $store->id,
                'fulfillment_status' => $status,
            ]);

            expect($order->fresh()->fulfillment_status)->toBe($status);
        }
    });
});

describe('damaged stock scoping', function () {
    beforeEach(function () {
        $this->store = Store::factory()->create();

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
            'quantity'     => 8,
        ]);
        Stock::create([
            'product_id'   => $this->product->id,
            'warehouse_id' => $this->damaged->id,
            'quantity'     => 3,
        ]);
    });

    it('creates the damaged warehouse once and keeps it out of the primary slot', function () {
        expect($this->damaged->type)->toBe(Warehouse::TYPE_DAMAGED)
            ->and($this->damaged->isSellable())->toBeFalse()
            ->and($this->primary->id)->not->toBe($this->damaged->id)
            ->and($this->store->getDamagedWarehouse()->id)->toBe($this->damaged->id)
            ->and($this->store->warehouses()->where('type', Warehouse::TYPE_DAMAGED)->count())->toBe(1);
    });

    it('reports damaged units separately from sellable ones', function () {
        $product = Product::query()->withSellableStock()->find($this->product->id);

        expect((int) $product->total_stock)->toBe(8)
            ->and((int) $product->damaged_stock)->toBe(3);
    });

    it('excludes damaged units from the product stock helpers', function () {
        expect($this->product->getTotalStock())->toBe(8)
            ->and($this->product->getDisplayStock())->toBe(8)
            // An explicitly named warehouse is still answered as asked.
            ->and($this->product->getTotalStock($this->damaged->id))->toBe(3);
    });

    it('values only sellable inventory', function () {
        // 8 sellable × 100, not 11 × 100.
        expect($this->store->getTotalStockValue())->toBe(800.0);
    });
});
