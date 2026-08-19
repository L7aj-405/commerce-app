<?php

declare(strict_types=1);

use App\Mail\InvoiceMail;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: User, 1: Store}
 */
function dashStore(): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function dashOrder(Store $store): Order
{
    return Order::factory()->create([
        'store_id'      => $store->id,
        'total'         => 90,
        'customer_name' => 'Dash Customer',
        'customer_email'=> 'dash@example.com',
        'items'         => [['name' => 'Thing', 'quantity' => 3, 'unit_price' => 30, 'line_total' => 90]],
    ]);
}

function dashIssue(Store $store, User $issuer): \App\Models\Facture
{
    return app(InvoiceService::class)->issueFor(dashOrder($store), $issuer, ['generate_pdf' => false]);
}

it('generates a finalized invoice from an order via the dashboard', function (): void {
    Storage::fake('local');
    [$owner, $store] = dashStore();
    $order = dashOrder($store);

    $this->actingAs($owner)
        ->post('/dashboard/invoices', ['source_type' => 'order', 'source_id' => $order->id])
        ->assertRedirect();

    expect($order->fresh()->invoice()->exists())->toBeTrue();
});

it('renders the invoice detail with audit trail and capability flags for an admin', function (): void {
    [$owner, $store] = dashStore();
    $facture = dashIssue($store, $owner);

    $this->actingAs($owner)
        ->get("/dashboard/factures/{$facture->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/FacturesDetail')
            ->has('activities')
            ->where('can.amend', true)
            ->where('can.void', true));
});

it('hides amend/void from a member without those permissions', function (): void {
    [$owner, $store] = dashStore();
    $facture = dashIssue($store, $owner);

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
        ->get("/dashboard/factures/{$facture->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.amend', false)
            ->where('can.void', false));
});

it('amends an invoice through the dashboard endpoint', function (): void {
    Storage::fake('local');
    [$owner, $store] = dashStore();
    $facture = dashIssue($store, $owner);

    $this->actingAs($owner)
        ->patch("/dashboard/invoices/{$facture->id}", ['reason' => 'Fix name', 'customer_name' => 'Corrected Name'])
        ->assertRedirect();

    expect($facture->fresh()->customer_name)->toBe('Corrected Name');
});

it('emails the invoice and only marks it sent once delivery succeeds', function (): void {
    Storage::fake('local');
    Mail::fake();
    [$owner, $store] = dashStore();
    $facture = dashIssue($store, $owner);

    expect($facture->sent_at)->toBeNull();

    $this->actingAs($owner)
        ->post("/dashboard/invoices/{$facture->id}/email")
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(InvoiceMail::class);

    $fresh = $facture->fresh();
    expect($fresh->sent_at)->not->toBeNull();
    expect($fresh->status)->toBe('sent');
});

it('rejects emailing an invoice that has no customer email', function (): void {
    Storage::fake('local');
    Mail::fake();
    [$owner, $store] = dashStore();
    $facture = dashIssue($store, $owner);
    $facture->update(['customer_email' => null]);

    $this->actingAs($owner)
        ->post("/dashboard/invoices/{$facture->id}/email")
        ->assertSessionHasErrors('email');

    Mail::assertNothingSent();
    expect($facture->fresh()->sent_at)->toBeNull();
});

it('streams an 80mm thermal receipt PDF for the invoice', function (): void {
    [$owner, $store] = dashStore();
    $facture = dashIssue($store, $owner);

    $response = $this->actingAs($owner)->get("/dashboard/invoices/{$facture->id}/receipt");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});

it('voids and records payment through the dashboard endpoints', function (): void {
    [$owner, $store] = dashStore();

    $paid = dashIssue($store, $owner);
    $this->actingAs($owner)->post("/dashboard/invoices/{$paid->id}/pay", ['amount' => 90])->assertRedirect();
    expect($paid->fresh()->payment_status)->toBe('paid');

    $voided = dashIssue($store, $owner);
    $this->actingAs($owner)->post("/dashboard/invoices/{$voided->id}/void", ['reason' => 'Duplicate'])->assertRedirect();
    expect($voided->fresh()->status)->toBe('void');
});
