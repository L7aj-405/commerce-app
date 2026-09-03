<?php

declare(strict_types=1);

use App\Enums\FulfillmentDocumentType;
use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\DocumentTemplate;
use App\Models\FinanceTransaction;
use App\Models\FulfillmentDocument;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Documents\DocumentTemplateResolver;
use App\Services\Documents\PickPackTicketService;
use App\Services\OrganizationProvisioner;
use App\Services\Pos\DocumentGenerationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Internal pick / pack ticket — read-only PDF, template-driven, tenant-safe.
|--------------------------------------------------------------------------
*/

function pptWorkspace(string $name = 'PPT Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $store = Store::factory()->create(['user_id' => $owner->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pptMember(Store $store, string $roleName): User
{
    $role = $store->roles()->where('name', $roleName)->firstOrFail();
    // users.role / store_members.role are the coarse dashboard-vs-POS gate
    // (store_admin|manager|cashier only); the store_role_id supplies perms.
    $user = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $user->id, 'role' => 'manager',
        'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now(),
    ]);

    return $user;
}

function pptCity(): City
{
    return City::query()->where('country_code', 'MA')->where('is_active', true)->orderBy('name')->firstOrFail();
}

function pptOrder(Store $store, FulfillmentStatus $status = FulfillmentStatus::Confirmed, array $overrides = []): Order
{
    $city = pptCity();

    return Order::factory()->create(array_merge([
        'store_id' => $store->id,
        'platform_data' => [],
        'fulfillment_status' => $status,
        'order_number' => 'PPT-' . strtoupper(fake()->bothify('####')),
        'customer_name' => 'Yasmine Alaoui',
        'customer_phone' => '0655443322',
        'shipping_city_id' => $city->id,
        'confirmed_shipping_address' => '4 Rue des Oliviers, Maârif',
        'total' => 349.90,
        'currency' => 'MAD',
        'notes' => 'Call before delivery.',
        'items' => [
            ['name' => 'Cotton Hoodie', 'sku' => 'HOOD-BLK-M', 'variant' => 'Black / M', 'barcode' => '6111234567890', 'quantity' => 2],
            ['name' => 'Sticker Pack', 'quantity' => 3],
        ],
    ], $overrides));
}

beforeEach(function () {
    Storage::fake('local');
});

it('prints a pick/pack ticket PDF for a confirmed online order', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $order = pptOrder($store, FulfillmentStatus::Confirmed);

    $response = $this->actingAs($picker)->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('blocks a pick/pack ticket for a pending (unconfirmed) order', function () {
    [$owner, $store] = pptWorkspace();
    $order = pptOrder($store, FulfillmentStatus::Pending);

    $this->actingAs($owner)
        ->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket")
        ->assertStatus(422);
});

it('gives a clear "must be confirmed" message for a pending order', function () {
    [, $store] = pptWorkspace();
    $order = pptOrder($store, FulfillmentStatus::Pending);

    try {
        app(PickPackTicketService::class)->assertEligible($order);
        $this->fail('Expected a ValidationException for a pending order.');
    } catch (ValidationException $e) {
        expect($e->errors()['order'][0])->toBe('Order must be confirmed before printing a pick/pack ticket.');
    }
});

it('renders the order reference, customer, city and COD amount on the ticket', function () {
    [, $store] = pptWorkspace();
    $order = pptOrder($store, FulfillmentStatus::Packing);

    $html = pptRenderHtml($order);

    expect($html)
        ->toContain($order->order_number)
        ->toContain('Yasmine Alaoui')
        ->toContain($order->shippingCity->name)
        ->toContain('4 Rue des Oliviers')
        ->toContain('349.90')
        ->toContain('Cash on delivery (COD)')
        ->toContain('Call before delivery.');
});

it('lists item names, variants, SKU and quantities', function () {
    [, $store] = pptWorkspace();
    $order = pptOrder($store, FulfillmentStatus::Picking);

    $html = pptRenderHtml($order);

    expect($html)
        ->toContain('Cotton Hoodie')
        ->toContain('HOOD-BLK-M')
        ->toContain('Black / M')
        ->toContain('Sticker Pack');
});

it('does not break when an item has no variant, SKU or barcode', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $order = pptOrder($store, FulfillmentStatus::Confirmed, [
        'items' => [['name' => 'Mystery Item', 'quantity' => 1]],
    ]);

    $data = app(DocumentGenerationService::class)->pickPackTicketData($order);
    expect($data['items'][0]['sku'])->toBeNull()
        ->and($data['items'][0]['variant'])->toBeNull()
        ->and($data['items'][0]['barcode'])->toBeNull()
        ->and($data['items'][0]['quantity'])->toBe(1);

    $this->actingAs($picker)
        ->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket")
        ->assertOk();
});

it('renders a ticket even when the order has no items, with a warning', function () {
    [, $store] = pptWorkspace();
    $order = pptOrder($store, FulfillmentStatus::Confirmed, ['items' => []]);

    $html = pptRenderHtml($order);

    expect($html)->toContain('No items found');
});

it('does not change the order status when the ticket is printed', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $order = pptOrder($store, FulfillmentStatus::ReadyForPicking);

    $this->actingAs($picker)->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket")->assertOk();

    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking);
});

it('creates no finance transaction when a ticket is printed', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $order = pptOrder($store, FulfillmentStatus::Confirmed);

    $before = FinanceTransaction::query()->count();

    $this->actingAs($picker)->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket")->assertOk();

    expect(FinanceTransaction::query()->count())->toBe($before);
});

it('forbids a user without fulfillment.documents.print', function () {
    [, $store] = pptWorkspace();
    $viewer = pptMember($store, 'Viewer'); // orders.view but no fulfilment perms
    $order = pptOrder($store, FulfillmentStatus::Confirmed);

    $this->actingAs($viewer)
        ->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket")
        ->assertForbidden();
});

it('never lets another store print this order\'s ticket', function () {
    [, $storeA] = pptWorkspace('PPT Owner Store');
    [$ownerB] = pptWorkspace('PPT Other Store');
    $order = pptOrder($storeA, FulfillmentStatus::Confirmed);

    $this->actingAs($ownerB)
        ->get("/dashboard/orders/online/{$order->id}/pick-pack-ticket")
        ->assertNotFound();
});

it('produces one combined PDF for several selected orders (bulk print)', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $a = pptOrder($store, FulfillmentStatus::Confirmed);
    $b = pptOrder($store, FulfillmentStatus::Packing);

    $response = $this->actingAs($picker)->get("/dashboard/orders/pick-pack-tickets?ids[]={$a->id}&ids[]={$b->id}");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects a bulk print when none of the selected orders are confirmed', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $a = pptOrder($store, FulfillmentStatus::Pending);
    $b = pptOrder($store, FulfillmentStatus::Pending);

    $this->actingAs($picker)
        ->get("/dashboard/orders/pick-pack-tickets?ids[]={$a->id}&ids[]={$b->id}")
        ->assertStatus(422);
});

it('saves a private pick_ticket FulfillmentDocument copy on the order', function () {
    [, $store] = pptWorkspace();
    $picker = pptMember($store, 'Warehouse');
    $order = pptOrder($store, FulfillmentStatus::Confirmed);

    $this->actingAs($picker)
        ->post("/dashboard/orders/online/{$order->id}/pick-pack-ticket/save")
        ->assertRedirect()
        ->assertSessionHas('success');

    $doc = FulfillmentDocument::query()
        ->where('documentable_type', $order->getMorphClass())
        ->where('documentable_id', $order->id)
        ->sole();

    expect($doc->document_type)->toBe(FulfillmentDocumentType::PickTicket)
        ->and($doc->provider_code)->toBeNull()
        ->and($doc->status->value)->toBe('generated')
        ->and($doc->is_downloadable)->toBeTrue()
        ->and(Storage::disk('local')->exists($doc->path))->toBeTrue();
});

/*
| Document template foundation
*/

it('resolves the system default template when no custom row exists', function () {
    $resolved = app(DocumentTemplateResolver::class)->resolve('pick_ticket');

    expect($resolved->isCustom)->toBeFalse()
        ->and($resolved->view)->toBe('documents.pick-pack-ticket')
        ->and($resolved->setting('paper_format'))->toBe('A5')
        ->and($resolved->label('title'))->toBe('Pick / Pack Ticket')
        ->and($resolved->fieldVisible('checklist'))->toBeTrue();
});

it('deep-merges an active custom template over the system defaults', function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Tpl Org');

    DocumentTemplate::create([
        'organization_id' => $organization->id,
        'store_id' => null,
        'document_type' => 'pick_ticket',
        'name' => 'Custom FR ticket',
        'is_active' => true,
        'settings' => [
            'paper_format' => 'A4',
            'labels' => ['title' => 'BON DE PRÉPARATION'],
        ],
    ]);

    $resolved = app(DocumentTemplateResolver::class)->resolve('pick_ticket', $organization->fresh());

    expect($resolved->isCustom)->toBeTrue()
        ->and($resolved->setting('paper_format'))->toBe('A4')          // overridden
        ->and($resolved->label('title'))->toBe('BON DE PRÉPARATION')   // overridden
        ->and($resolved->label('qty'))->toBe('Qty')                    // kept from default (deep merge)
        ->and($resolved->view)->toBe('documents.pick-pack-ticket');    // still the system view
});

it('ignores an inactive custom template', function () {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Tpl Org 2');

    DocumentTemplate::create([
        'organization_id' => $organization->id, 'store_id' => null,
        'document_type' => 'pick_ticket', 'name' => 'Disabled', 'is_active' => false,
        'settings' => ['paper_format' => 'A4'],
    ]);

    $resolved = app(DocumentTemplateResolver::class)->resolve('pick_ticket', $organization->fresh());

    expect($resolved->isCustom)->toBeFalse()
        ->and($resolved->setting('paper_format'))->toBe('A5');
});

it('never treats provider label document types as customizable', function () {
    expect(FulfillmentDocumentType::customizableValues())
        ->toContain('pick_ticket')
        ->not->toContain('carrier_label')
        ->not->toContain('delivery_note')
        ->and(FulfillmentDocumentType::CarrierLabel->isCustomizable())->toBeFalse()
        ->and(FulfillmentDocumentType::PickTicket->isCustomizable())->toBeTrue();
});

/** Render the pick/pack ticket HTML (not the PDF) so content can be asserted. */
function pptRenderHtml(Order $order): string
{
    $svc = app(DocumentGenerationService::class);
    $resolver = app(DocumentTemplateResolver::class);

    return View::make('documents.pick-pack-ticket', [
        'data' => $svc->pickPackTicketData($order),
        'template' => $resolver->resolve('pick_ticket', $order->store?->organization, $order->store_id),
    ])->render();
}
