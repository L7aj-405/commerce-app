<?php

declare(strict_types=1);

use App\Models\Facture;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{0: User, 1: Store}
 */
function invoiceStore(): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function makeOrder(Store $store): Order
{
    return Order::factory()->create([
        'store_id'       => $store->id,
        'total'          => 120,
        'customer_name'  => 'Jane Doe',
        'customer_email' => 'jane@example.com',
        'items'          => [
            ['name' => 'Widget', 'sku' => 'W1', 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100],
            ['name' => 'Gadget', 'sku' => 'G1', 'quantity' => 1, 'unit_price' => 20, 'line_total' => 20],
        ],
    ]);
}

function svc(): InvoiceService
{
    return app(InvoiceService::class);
}

it('issues an immutable invoice with a frozen line-item snapshot', function (): void {
    [$owner, $store] = invoiceStore();
    $order   = makeOrder($store);
    $facture = svc()->issueFor($order, $owner, ['generate_pdf' => false]);

    expect($facture->status)->toBe(Facture::STATUS_ISSUED)
        ->and($facture->isLocked())->toBeTrue()
        ->and($facture->issued_by)->toBe($owner->id)
        ->and($facture->invoice_number)->toStartWith('INV-')
        ->and($facture->items)->toHaveCount(2)
        ->and((float) $facture->total_amount)->toBe(120.0)
        ->and($facture->invoiceable_type)->toBe(Order::class);

    expect($order->invoice()->exists())->toBeTrue();
});

it('is idempotent — issuing twice returns the same invoice', function (): void {
    [$owner, $store] = invoiceStore();
    $order = makeOrder($store);

    $a = svc()->issueFor($order, $owner, ['generate_pdf' => false]);
    $b = svc()->issueFor($order->fresh(), $owner, ['generate_pdf' => false]);

    expect($b->id)->toBe($a->id)
        ->and(Facture::count())->toBe(1);
});

it('records an audit entry with old/new values and a reason on amendment', function (): void {
    [$owner, $store] = invoiceStore();
    $facture = svc()->issueFor(makeOrder($store), $owner, ['generate_pdf' => false]);

    svc()->amend($facture, $owner, ['customer_name' => 'New Name'], 'Customer requested a name correction');

    $activity = Activity::where('log_name', 'invoice')->latest('id')->first();

    expect($facture->fresh()->customer_name)->toBe('New Name')
        ->and($activity)->not->toBeNull()
        ->and($activity->properties['reason'] ?? null)->toBe('Customer requested a name correction')
        ->and($activity->properties['old']['customer_name'] ?? null)->toBe('Jane Doe')
        ->and($activity->properties['attributes']['customer_name'] ?? null)->toBe('New Name');
});

it('refuses to amend without a reason', function (): void {
    [$owner, $store] = invoiceStore();
    $facture = svc()->issueFor(makeOrder($store), $owner, ['generate_pdf' => false]);

    svc()->amend($facture, $owner, ['customer_name' => 'X'], '   ');
})->throws(RuntimeException::class);

it('voids an invoice with a mandatory reason', function (): void {
    [$owner, $store] = invoiceStore();
    $facture = svc()->issueFor(makeOrder($store), $owner, ['generate_pdf' => false]);

    svc()->void($facture, $owner, 'Duplicate of INV-x');

    expect($facture->fresh()->status)->toBe(Facture::STATUS_VOID)
        ->and($facture->fresh()->void_reason)->toBe('Duplicate of INV-x');
});

it('records partial then full payment', function (): void {
    [$owner, $store] = invoiceStore();
    $facture = svc()->issueFor(makeOrder($store), $owner, ['generate_pdf' => false]);

    svc()->recordPayment($facture, $owner, 50);
    expect($facture->fresh()->payment_status)->toBe('partial');

    svc()->recordPayment($facture->fresh(), $owner, 70);
    expect($facture->fresh()->payment_status)->toBe('paid')
        ->and($facture->fresh()->status)->toBe(Facture::STATUS_PAID);
});

it('blocks a manager without invoices.amend from amending a finalized invoice', function (): void {
    [$owner, $store] = invoiceStore();
    $facture = svc()->issueFor(makeOrder($store), $owner, ['generate_pdf' => false]);

    $manager = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id'      => $store->id,
        'user_id'       => $manager->id,
        'role'          => 'manager',
        'store_role_id' => $store->roles()->where('slug', 'manager')->first()->id,
        'is_active'     => true,
        'joined_at'     => now(),
    ]);

    $this->actingAs($manager)
        ->patch("/dashboard/invoices/{$facture->id}", ['reason' => 'trying', 'customer_name' => 'Hacked'])
        ->assertForbidden();

    expect($facture->fresh()->customer_name)->toBe('Jane Doe');
});

it('prevents cross-tenant access to another store’s invoice', function (): void {
    [$ownerA, $storeA] = invoiceStore();
    $facture = svc()->issueFor(makeOrder($storeA), $ownerA, ['generate_pdf' => false]);

    [$ownerB] = invoiceStore(); // different owner + store

    $this->actingAs($ownerB)
        ->get("/dashboard/invoices/{$facture->id}/download")
        ->assertForbidden();
});
