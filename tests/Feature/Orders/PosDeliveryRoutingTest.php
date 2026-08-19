<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Pos\OrderProcessingService;
use App\Support\OrderPresenter;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| POS delivery bypasses the confirmation department
|--------------------------------------------------------------------------
| Online orders still start Pending (confirmation); a POS delivery order is
| pre-approved by the cashier and enters the workflow already Confirmed, so it
| lands in the packing queue, never the confirmation queue.
*/

beforeEach(function () {
    Queue::fake();

    $this->owner   = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store   = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();
    $this->store->getPrimaryWarehouse();

    $this->product = Product::create([
        'store_id' => $this->store->id, 'name' => 'Blue Hoodie', 'sku' => 'BH-1',
        'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]);
    Stock::create([
        'product_id'   => $this->product->id,
        'warehouse_id' => $this->store->getPrimaryWarehouse()->id,
        'quantity'     => 20,
    ]);

    $this->session = PosSession::create([
        'store_id' => $this->store->id, 'cashier_id' => $this->owner->id,
        'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);

    $this->checkout = fn (string $fulfillmentType) => app(OrderProcessingService::class)->createOrder(
        store: $this->store,
        cashier: $this->owner,
        data: [
            'pos_session_id'   => $this->session->id,
            'fulfillment_type' => $fulfillmentType,
            'delivery_address' => $fulfillmentType === 'delivery' ? '12 Rue Test, Casablanca' : null,
            'payment_method'   => 'cash',
            'total_amount'     => 100,
            'amount_paid'      => 100,
            'items'            => [[
                'product_id' => $this->product->id, 'product_name' => 'Blue Hoodie',
                'unit_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'line_total' => 100,
            ]],
        ],
    );
});

it('creates a POS delivery order already confirmed, skipping the confirmation desk', function () {
    $order = ($this->checkout)('delivery');

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::Confirmed)
        // The legacy channel status still records that this is a delivery order.
        ->and($order->status)->toBe('pending_delivery');
});

it('leaves an instant POS sale completed on the spot', function () {
    $order = ($this->checkout)('instant');

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::Completed)
        ->and($order->status)->toBe('completed');
});

it('routes a POS delivery order into the packing phase, not confirmation', function () {
    $order = ($this->checkout)('delivery');

    $row = OrderPresenter::pos($order->fresh()->load('items'));

    expect($row['phase'])->toBe('fulfillment')          // packing department
        ->and($row['source'])->toBe('pos')              // channel only — always the POS
        ->and($row['source_label'])->toBe('Direct POS') // never a workflow stage
        ->and($row['is_delivery'])->toBeTrue()          // delivery lives on its own field
        ->and($row['fulfillment_type'])->toBe('delivery')
        ->and($row['status'])->toBe('confirmed');
});

it('shows a POS delivery order in the packing dashboard and not the confirmation one', function () {
    ($this->checkout)('delivery');

    // Packing queue (fulfillment phase) — order is present.
    $this->actingAs($this->owner)
        ->get('/dashboard/departments/packing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 1));

    // Confirmation queue (confirmation phase) — empty.
    $this->actingAs($this->owner)
        ->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('does not deduct stock a second time when a POS delivery order is packed', function () {
    $order = ($this->checkout)('delivery');
    app(OrderProcessingService::class)->adjustInventory($order);

    $afterSale = (int) Stock::where('product_id', $this->product->id)->value('quantity');
    expect($afterSale)->toBe(19);   // committed once, at checkout

    // Advancing through packing must not touch stock again (POS committed already).
    app(App\Services\Orders\OrderWorkflowService::class)
        ->transition($order, FulfillmentStatus::InProgress, $this->owner);

    expect((int) Stock::where('product_id', $this->product->id)->value('quantity'))->toBe(19);
});
