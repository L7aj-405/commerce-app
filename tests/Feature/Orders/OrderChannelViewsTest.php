<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\Facture;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Multi-channel order views: unified list, dashboard, online invoice/receipt
|--------------------------------------------------------------------------
| Covers the three fixes: online orders reach the invoice + thermal receipt
| generators, the dashboard "recent orders" spans both channels, and the
| orders list filters accurately by source (POS vs Online).
*/

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $this->store = Store::factory()->create(['user_id' => $this->owner->id]);
    $this->store->ensureDefaultRoles();

    $session = PosSession::create([
        'store_id' => $this->store->id, 'cashier_id' => $this->owner->id,
        'status' => 'open', 'opening_balance' => 0, 'opened_at' => now(),
    ]);

    $this->pos = PosOrder::create([
        'store_id'           => $this->store->id,
        'pos_session_id'     => $session->id,
        'cashier_id'         => $this->owner->id,
        'receipt_number'     => 'RCP-1',
        'status'             => 'completed',
        'fulfillment_status' => FulfillmentStatus::Completed,
        'subtotal'           => 100,
        'tax_amount'         => 0,
        'discount_amount'    => 0,
        'total_amount'       => 100,
        'payment_method'     => 'cash',
        'amount_paid'        => 100,
        'change_amount'      => 0,
        'customer_name'      => 'Walk-in',
    ]);

    $this->online = Order::factory()->create([
        'store_id'      => $this->store->id,
        'order_number'  => 'WEB-1001',
        'customer_name' => 'Amina Online',
        'items'         => [[
            'name' => 'Blue Hoodie', 'sku' => 'BH-1', 'quantity' => 2, 'price' => 150,
        ]],
        'total'         => 300,
    ]);
});

it('lists both POS and online orders in the unified orders index', function () {
    $this->actingAs($this->owner)
        ->get('/dashboard/orders')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Orders/Index')
            ->has('orders.data', 2));
});

it('filters the orders index to POS only', function () {
    $this->actingAs($this->owner)
        ->get('/dashboard/orders?source=pos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.origin', 'pos')
            ->where('orders.data.0.reference', 'RCP-1'));
});

it('filters the orders index to online only', function () {
    $this->actingAs($this->owner)
        ->get('/dashboard/orders?source=online')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.origin', 'online')
            ->where('orders.data.0.reference', 'WEB-1001'));
});

it('shows both channels in the dashboard recent orders widget', function () {
    $this->actingAs($this->owner)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recent_orders', 2)
            ->where('recent_orders', fn ($rows) => collect($rows)->pluck('origin')->sort()->values()->all() === ['online', 'pos']));
});

it('renders the online order detail page', function () {
    $this->actingAs($this->owner)
        ->get("/dashboard/orders/online/{$this->online->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Orders/ShowOnline')
            ->where('order.reference', 'WEB-1001'));
});

it('streams a thermal receipt PDF for an online order', function () {
    $response = $this->actingAs($this->owner)
        ->get("/dashboard/orders/online/{$this->online->id}/receipt");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('generates a formal A4 invoice and thermal receipt for an online order', function () {
    // Issue the invoice from the online order (same path POS orders use).
    $this->actingAs($this->owner)
        ->post('/dashboard/invoices', [
            'source_type' => 'order',
            'source_id'   => $this->online->id,
        ])
        ->assertRedirect();

    $facture = Facture::where('store_id', $this->store->id)->firstOrFail();
    expect($facture->invoiceable_id)->toBe($this->online->id)
        ->and((float) $facture->total_amount)->toBe(300.0);

    // A4 invoice PDF.
    $a4 = $this->actingAs($this->owner)->get("/dashboard/invoices/{$facture->id}/download");
    $a4->assertOk();

    // Thermal receipt PDF.
    $thermal = $this->actingAs($this->owner)->get("/dashboard/invoices/{$facture->id}/receipt");
    $thermal->assertOk();
    expect(substr($thermal->getContent(), 0, 4))->toBe('%PDF');
});

it('scopes the online order detail to the owning store', function () {
    $otherOwner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $otherStore = Store::factory()->create(['user_id' => $otherOwner->id]);
    $otherStore->ensureDefaultRoles();

    // Mirrors the POS show() convention: cross-tenant access is a hard 403.
    $this->actingAs($otherOwner)
        ->get("/dashboard/orders/online/{$this->online->id}")
        ->assertForbidden();
});
