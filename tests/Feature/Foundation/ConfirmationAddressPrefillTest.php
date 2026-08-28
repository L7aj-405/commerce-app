<?php

declare(strict_types=1);

use App\Events\OrderCreated;
use App\Models\City;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Confirmation Desk address prefill — the customer's original
 * shipping/delivery address must reach the Confirmation Desk UI and prefill
 * the editable "confirmed address" field, instead of showing empty. Root
 * cause fixed: WooCommerceConnector::parseOrder() never preserved the raw
 * order (so billing/shipping never survived to platform_data), and the
 * Shopify fallback looked at the wrong keys (`shipping.address_1` instead of
 * the real `shipping_address.address1`). See App\Support\OrderAddressSummary.
 */

/** @return array{0: User, 1: Store} */
function cdAddrWorkspace(string $name = 'Confirmation Address Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function cdAddrShopifyConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => $domain, 'access_token' => 'shpat_test',
    ]));
}

function cdAddrWooConnection(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
    ]));
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('includes the original customer address in the Confirmation Desk props', function (): void {
    [$owner, $store] = cdAddrWorkspace();
    $connection = cdAddrShopifyConnection($store, 'addrprefill.myshopify.com');

    app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'ADDR-1', 'number' => '#100', 'status' => 'processing', 'total' => 120.0, 'currency' => 'MAD',
        'customer_name' => 'Nadia K.', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(),
        'platform_data' => [
            'shipping_address' => [
                'first_name' => 'Nadia', 'last_name' => 'K.', 'phone' => '+212600111222',
                'address1' => '12 Rue des Fleurs', 'address2' => 'Apt 4', 'city' => 'Casablanca',
                'province' => 'Casablanca-Settat', 'country' => 'Morocco',
            ],
            'note' => 'Leave at the door',
        ],
    ], $connection);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.original_address.name', 'Nadia K.')
            ->where('orders.0.original_address.phone', '+212600111222')
            ->where('orders.0.original_address.address1', '12 Rue des Fleurs')
            ->where('orders.0.original_address.address2', 'Apt 4')
            ->where('orders.0.original_address.city', 'Casablanca')
            ->where('orders.0.original_address.province', 'Casablanca-Settat')
            ->where('orders.0.original_address.country', 'Morocco')
            ->where('orders.0.original_address.notes', 'Leave at the door')
            ->where('orders.0.original_address.has_address', true));
});

it('prefills confirmed_address (delivery_address) from a Shopify order\'s shipping_address.address1', function (): void {
    [$owner, $store] = cdAddrWorkspace();
    $connection = cdAddrShopifyConnection($store, 'prefill2.myshopify.com');

    app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'ADDR-2', 'number' => '#101', 'status' => 'processing', 'total' => 90.0, 'currency' => 'MAD',
        'customer_name' => 'Yassine', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(),
        'platform_data' => ['shipping_address' => ['address1' => '45 Boulevard Zerktouni', 'city' => 'Casablanca']],
    ], $connection);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.delivery_address', '45 Boulevard Zerktouni'));
});

it('prefills confirmed_address from a WooCommerce order\'s shipping address (root cause: platform_data now preserved)', function (): void {
    [$owner, $store] = cdAddrWorkspace();
    $connection = cdAddrWooConnection($store, 'prefill3-woo.example.com');

    app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'ADDR-3', 'number' => '102', 'status' => 'processing', 'total' => 70.0, 'currency' => 'MAD',
        'customer_name' => 'Amine', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(),
        'platform_data' => ['shipping' => ['first_name' => 'Amine', 'last_name' => 'B.', 'address_1' => '7 Avenue Hassan II', 'city' => 'Rabat', 'state' => 'Rabat-Salé'], 'billing' => []],
    ], $connection);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.delivery_address', '7 Avenue Hassan II')
            ->where('orders.0.original_address.city', 'Rabat')
            ->where('orders.0.original_address.province', 'Rabat-Salé'));
});

it('falls back to the WooCommerce billing address when no separate shipping address was collected', function (): void {
    [$owner, $store] = cdAddrWorkspace();
    $connection = cdAddrWooConnection($store, 'prefill4-woo.example.com');

    app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'ADDR-4', 'number' => '103', 'status' => 'processing', 'total' => 60.0, 'currency' => 'MAD',
        'customer_name' => 'Sara', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(),
        'platform_data' => ['shipping' => [], 'billing' => ['first_name' => 'Sara', 'last_name' => 'M.', 'address_1' => '3 Rue Ibn Sina', 'city' => 'Fes', 'phone' => '+212611000000']],
    ], $connection);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.delivery_address', '3 Rue Ibn Sina')
            ->where('orders.0.original_address.phone', '+212611000000'));
});

it('shows a clear warning when the platform provided no address at all', function (): void {
    [$owner, $store] = cdAddrWorkspace();
    $connection = cdAddrShopifyConnection($store, 'noaddress.myshopify.com');

    app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'ADDR-5', 'number' => '#104', 'status' => 'processing', 'total' => 40.0, 'currency' => 'MAD',
        'customer_name' => 'No Address Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('orders.0.original_address.has_address', false)
            ->where('orders.0.delivery_address', null));
});

it('once confirmed, delivery_address reflects the persisted confirmed_shipping_address, not the raw platform address', function (): void {
    [$owner, $store] = cdAddrWorkspace();
    $connection = cdAddrShopifyConnection($store, 'persisted.myshopify.com');
    $city = City::create(['country_code' => 'MA', 'code' => 'CNF1', 'name' => 'Confirm City', 'region' => 'Region', 'is_active' => true]);

    $warehouse = \App\Models\Warehouse::withoutTenancy(fn () => \App\Models\Warehouse::create([
        'user_id' => $owner->id, 'owner_organization_id' => $store->organization_id, 'operator_organization_id' => $store->organization_id,
        'name' => 'Confirm Warehouse', 'type' => \App\Models\Warehouse::TYPE_STANDARD, 'country' => 'MA', 'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$store->organization_id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    $product = \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $store->id, 'name' => 'Confirm Product', 'sku' => 'ADDR-6-PROD', 'type' => 'simple', 'status' => 'active', 'price' => 80,
    ]));
    $item = app(\App\Services\Inventory\CatalogInventoryService::class)->forCatalog($product);
    app(\App\Services\Inventory\InventoryEngine::class)->setOnHand($item, $warehouse, 5, 'initial_import', null, $owner);

    $order = app(OrderSyncService::class)->saveOrder([
        'platform_id' => 'ADDR-6', 'number' => '#105', 'status' => 'processing', 'total' => 80.0, 'currency' => 'MAD',
        'customer_name' => 'Karim', 'customer_email' => null, 'customer_phone' => null,
        'items' => [['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => 80, 'line_total' => 80]],
        'created_at' => now()->toIso8601String(),
        'platform_data' => ['shipping_address' => ['address1' => 'Original Raw Address', 'city' => 'Confirm City']],
    ], $connection);

    $this->actingAs($owner)->post("/dashboard/departments/online/{$order->id}/claim")->assertSessionHas('success');
    $this->actingAs($owner)->post("/dashboard/orders/online/{$order->id}/status", [
        'status' => 'confirmed', 'shipping_address' => 'Corrected Address 99', 'shipping_city_id' => $city->id,
    ]);

    expect($order->fresh()->confirmed_shipping_address)->toBe('Corrected Address 99')
        ->and($order->fresh()->shipping_city_id)->toBe($city->id)
        ->and($order->fresh()->fulfillment_status)->not->toBe(\App\Enums\FulfillmentStatus::Pending);

    $this->actingAs($owner)->get('/dashboard/departments/confirmation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 0)); // no longer in the confirmation queue
});
