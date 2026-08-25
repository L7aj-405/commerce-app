<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Phase OST6 — an agency admin filtering by source (platform/connection)
 * must only ever see orders belonging to a client store the agency actually
 * operates/owns — never an unrelated client or an unrelated organization
 * entirely, even when a plausible connection id is guessed/pasted directly.
 * Cross-client queues also carry the source columns alongside the existing
 * client/store column.
 */

function ostAgencyConnection(Store $store, string $platform, array $overrides = []): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create(array_merge([
        'store_id' => $store->id, 'platform' => $platform, 'status' => 'active',
    ], $overrides)));
}

function ostAgencyOnlineOrder(PlatformConnection $connection, string $externalId): Order
{
    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "REF-{$externalId}", 'status' => 'processing',
        'total' => 100.0, 'currency' => 'MAD', 'customer_name' => 'Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('lets an agency admin filter a client store\'s online orders by platform without leaking another client', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Source Scope Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $owner, ['client_name' => 'Source Client A', 'brand_name' => 'Source Brand A', 'country' => 'MA', 'currency' => 'MAD']);
    $clientB = $service->createClient($agency, $owner, ['client_name' => 'Source Client B', 'brand_name' => 'Source Brand B', 'country' => 'MA', 'currency' => 'MAD']);

    $storeA = $clientA->stores->first();
    $storeB = $clientB->stores->first();

    $shopifyA = ostAgencyConnection($storeA, 'shopify', ['shop_domain' => 'client-a.myshopify.com', 'connection_method' => 'admin_token', 'access_token' => 'shpat_test']);
    $shopifyB = ostAgencyConnection($storeB, 'shopify', ['shop_domain' => 'client-b.myshopify.com', 'connection_method' => 'admin_token', 'access_token' => 'shpat_test']);

    ostAgencyOnlineOrder($shopifyA, 'CLIENT-A-1');
    ostAgencyOnlineOrder($shopifyB, 'CLIENT-B-1');

    // The agency owner opens Client A into session (active store) and
    // filters by platform — must see only Client A's Shopify order.
    $this->actingAs($owner)
        ->withSession(['store_id' => $storeA->id])
        ->get('/dashboard/orders?platform=shopify')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.reference', 'REF-CLIENT-A-1'));

    // Pasting Client B's connection id directly while Client A is active must
    // not leak Client B's order — store_id scoping wins regardless.
    $this->actingAs($owner)
        ->withSession(['store_id' => $storeA->id])
        ->get('/dashboard/orders?connection=' . $shopifyB->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders.data', 0));
});

it('shows source platform/connection info alongside the client column in the cross-client picking queue', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Cross Client Source Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $owner, ['client_name' => 'Queue Client A', 'brand_name' => 'Queue Brand A', 'country' => 'MA', 'currency' => 'MAD']);
    $warehouse = $service->createAgencyWarehouse($agency, $owner, ['name' => 'Source Scope Hub', 'city' => 'Casablanca', 'country' => 'MA']);
    $service->assignWarehouse($agency, $clientA, $warehouse, $owner);

    $storeA = $clientA->stores->first();
    $storeA->ensureDefaultRoles();
    \App\Models\StoreMember::create([
        'store_id' => $storeA->id, 'user_id' => $owner->id, 'role' => 'manager',
        'store_role_id' => $storeA->roles()->where('slug', 'warehouse')->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);

    $shopify = ostAgencyConnection($storeA, 'shopify', ['shop_domain' => 'queue-client-a.myshopify.com', 'connection_method' => 'admin_token', 'access_token' => 'shpat_test']);

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $storeA->id, 'name' => 'Queue Product', 'sku' => 'AGSRC-1', 'type' => 'simple', 'status' => 'active', 'price' => 100,
    ]));
    $item = app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $owner);

    $order = app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'QUEUE-A-1', 'number' => '#5005', 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'Queue Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]],
        'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $shopify);
    app(OrderWorkflowService::class)->transition($order->fresh(), FulfillmentStatus::Confirmed, $owner);

    $this->actingAs($owner)
        ->get('/dashboard/operations/picking')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('is_agency_context', true)
            ->has('orders', 1)
            ->where('orders.0.client_organization_name', 'Queue Client A')
            ->where('orders.0.source_platform', 'shopify')
            ->where('orders.0.connection_label', 'Shopify - queue-client-a.myshopify.com')
            ->where('orders.0.external_order_number', '#5005'));
});
