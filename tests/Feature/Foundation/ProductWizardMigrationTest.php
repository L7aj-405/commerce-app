<?php

declare(strict_types=1);

use App\Models\InventoryItem;
use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductInventoryLink;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

function pwmWorkspace(string $name = 'Wizard Migration Store'): array
{
    $owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'name' => $name,
    ]);
    $store->ensureDefaultRoles();

    return [$owner, $organization, $store];
}

it('loads the product create page as Inertia', function (): void {
    [$owner] = pwmWorkspace();

    $this->actingAs($owner)
        ->get('/dashboard/products/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Products/Create'));
});

it('loads the product edit page as Inertia', function (): void {
    [$owner, , $store] = pwmWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Widget', 'sku' => 'WID-1', 'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]));

    $this->actingAs($owner)
        ->get("/dashboard/products/{$product->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Products/Edit'));
});

it('does not resolve any active product create/edit route to Livewire/Volt', function (): void {
    foreach (['stores.products.create', 'stores.products.edit'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull();

        $action = $route->getActionName();
        expect($action)->not->toContain('Livewire')
            ->and($action)->not->toContain('ProductCreationWizard')
            ->and($action)->not->toContain('ProductEditWizard');
    }

    foreach (['dashboard.products.create', 'dashboard.products.edit'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route->getActionName())->toContain('ProductController');
    }
});

it('lets a merchant/store user create a simple product', function (): void {
    [$owner, , $store] = pwmWorkspace();

    $response = $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'New Simple Product',
        'sku' => 'SIMPLE-1',
        'price' => 49.99,
        'type' => 'simple',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $store->id)->where('sku', 'SIMPLE-1')->exists()))->toBeTrue();
});

it('scopes SKU uniqueness to the active store, not globally', function (): void {
    [$ownerA, , $storeA] = pwmWorkspace('Store A');
    [$ownerB, , $storeB] = pwmWorkspace('Store B');

    $this->actingAs($ownerA)->post('/dashboard/products', [
        'name' => 'Product A', 'sku' => 'SHARED-SKU', 'price' => 10, 'type' => 'simple',
    ])->assertSessionHasNoErrors();

    // Same SKU in a different store succeeds.
    $this->actingAs($ownerB)->post('/dashboard/products', [
        'name' => 'Product B', 'sku' => 'SHARED-SKU', 'price' => 20, 'type' => 'simple',
    ])->assertSessionHasNoErrors();

    // Duplicate SKU within the same store fails.
    $this->actingAs($ownerA)->post('/dashboard/products', [
        'name' => 'Product A2', 'sku' => 'SHARED-SKU', 'price' => 15, 'type' => 'simple',
    ])->assertSessionHasErrors(['sku']);

    expect(Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeA->id)->where('sku', 'SHARED-SKU')->count()))->toBe(1)
        ->and(Product::withoutTenancy(fn () => Product::query()->where('store_id', $storeB->id)->where('sku', 'SHARED-SKU')->count()))->toBe(1);
});

it('does not create a new Organization when a product is created', function (): void {
    [$owner] = pwmWorkspace();
    $before = Organization::count();

    $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'No New Org', 'sku' => 'NO-NEW-ORG', 'price' => 10, 'type' => 'simple',
    ])->assertSessionHasNoErrors();

    expect(Organization::count())->toBe($before);
});

it('creates the product under the acting users active tenant/store', function (): void {
    [$owner, , $store] = pwmWorkspace();

    $this->actingAs($owner)->post('/dashboard/products', [
        'name' => 'Tenant Scoped', 'sku' => 'TENANT-1', 'price' => 10, 'type' => 'simple',
    ])->assertSessionHasNoErrors();

    $product = Product::withoutTenancy(fn () => Product::query()->where('sku', 'TENANT-1')->firstOrFail());
    expect($product->store_id)->toBe($store->id);
});

it('does not remove existing channel listings when a product is edited', function (): void {
    [$owner, , $store] = pwmWorkspace();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Listed Product', 'sku' => 'LISTED-1', 'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]));
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => 'ext-1',
    ]));

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Listed Product Renamed',
        'sku' => 'LISTED-1',
        'type' => 'simple',
        'price' => 12,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->not->toBeNull();
});

it('does not remove existing inventory item links when a product is edited', function (): void {
    [$owner, , $store] = pwmWorkspace();
    $warehouse = $store->getPrimaryWarehouse();

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Inventory Linked', 'sku' => 'INV-LINK-1', 'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]));

    // Writing a Stock row triggers the self-healing bridge (Stock::saved())
    // which lazily creates the InventoryItem + ProductInventoryLink.
    Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'variant_id' => null, 'quantity' => 5, 'reorder_level' => 10]);

    $link = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->firstOrFail());
    $itemId = $link->inventory_item_id;

    $this->actingAs($owner)->patch("/dashboard/products/{$product->id}", [
        'name' => 'Inventory Linked Renamed',
        'sku' => 'INV-LINK-1',
        'type' => 'simple',
        'price' => 15,
        'status' => 'active',
        'qty' => 7,
    ])->assertSessionHasNoErrors();

    $linkAfter = ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()->where('product_id', $product->id)->first());

    expect($linkAfter)->not->toBeNull()
        ->and($linkAfter->inventory_item_id)->toBe($itemId)
        ->and(InventoryItem::withoutOrganizationTenancy(fn () => InventoryItem::query()->find($itemId)))->not->toBeNull();
});

it('does not let a user from another tenant create or edit a product in a foreign store', function (): void {
    [, , $storeA] = pwmWorkspace('Tenant A');
    [$ownerB] = pwmWorkspace('Tenant B');

    $foreignProduct = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $storeA->id, 'name' => 'Foreign Product', 'sku' => 'FOREIGN-1', 'type' => 'simple', 'status' => 'active', 'price' => 10,
    ]));

    $this->actingAs($ownerB)
        ->get("/dashboard/products/{$foreignProduct->id}/edit")
        ->assertNotFound();

    $this->actingAs($ownerB)
        ->patch("/dashboard/products/{$foreignProduct->id}", [
            'name' => 'Hijacked', 'sku' => 'FOREIGN-1', 'type' => 'simple', 'price' => 1, 'status' => 'active',
        ])
        ->assertNotFound();
});
