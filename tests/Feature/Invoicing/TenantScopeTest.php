<?php

declare(strict_types=1);

use App\Models\Facture;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Support\TenantContext;

/**
 * @return array{0: User, 1: Store}
 */
function tenantStore(): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function issueInvoiceFor(Store $store, User $issuer): Facture
{
    $order = Order::factory()->create([
        'store_id'      => $store->id,
        'total'         => 100,
        'customer_name' => 'Customer',
        'items'         => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
    ]);

    return app(InvoiceService::class)->issueFor($order, $issuer, ['generate_pdf' => false]);
}

it('scopes queries to the active tenant and is inert without one', function (): void {
    [$ownerA, $storeA] = tenantStore();
    [$ownerB, $storeB] = tenantStore();
    issueInvoiceFor($storeA, $ownerA);
    issueInvoiceFor($storeB, $ownerB);

    $context = app(TenantContext::class);

    // With tenant A active, only A's invoice is visible.
    $context->set($storeA->id);
    expect(Facture::count())->toBe(1);

    // The escape hatch (used by jobs/cross-store admin) sees everything.
    expect(Facture::withoutTenancy(fn (): int => Facture::count()))->toBe(2);

    // No tenant set (console, queue worker) => unscoped.
    $context->forget();
    expect(Facture::count())->toBe(2);
});

it('auto-fills store_id from the active tenant on create', function (): void {
    [, $store] = tenantStore();

    $context = app(TenantContext::class);
    $context->set($store->id);

    $facture = Facture::create([
        'invoice_number' => 'INV-AUTO-1',
        'status'         => Facture::STATUS_DRAFT,
        'payment_status' => 'unpaid',
        'invoice_date'   => now()->toDateString(),
        'customer_name'  => 'Auto Fill',
        'total_amount'   => 0,
    ]);

    expect($facture->store_id)->toBe($store->id);

    $context->forget();
});

it('never leaks another store’s invoice through route-model binding', function (): void {
    [$ownerA, $storeA] = tenantStore();
    $facture = issueInvoiceFor($storeA, $ownerA);

    [$ownerB] = tenantStore();

    // Owner B, whose active store differs, is blocked by the Policy.
    $this->actingAs($ownerB)
        ->get("/dashboard/invoices/{$facture->id}/download")
        ->assertForbidden();
});
