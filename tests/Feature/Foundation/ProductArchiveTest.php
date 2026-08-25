<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function pclAWorkspace(string $name = 'Cleanup Archive Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function pclAProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => "Archive Product {$sku}", 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
}

it('archives selected products and hides them from the default index', function (): void {
    [$owner, $store] = pclAWorkspace();
    $product = pclAProduct($store, 'ARCH-1');

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/archive', [
        'product_ids' => [$product->id],
    ])->assertOk()->assertJsonPath('results.0.archived', true);

    expect($product->fresh()->status)->toBe('archived');

    $this->actingAs($owner)->get('/dashboard/products')
        ->assertInertia(fn ($page) => $page->where(
            'products.data',
            fn ($data) => ! collect($data)->pluck('id')->contains($product->id)
        ));

    $this->actingAs($owner)->get('/dashboard/products?archived=1')
        ->assertInertia(fn ($page) => $page->where(
            'products.data',
            fn ($data) => collect($data)->pluck('id')->contains($product->id)
        ));
});

it('keeps order history intact after archiving a product', function (): void {
    [$owner, $store] = pclAWorkspace();
    $product = pclAProduct($store, 'ARCH-2');

    $order = Order::factory()->create([
        'store_id' => $store->id,
        'order_number' => 'ARCH-ORDER-1',
        'total' => 20,
        'items' => [[
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'quantity' => 1, 'unit_price' => 20, 'line_total' => 20,
        ]],
    ]);

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/archive', [
        'product_ids' => [$product->id],
    ])->assertOk();

    // The product row itself must never be soft-deleted by archive — only its
    // status changes — so historical order display keeps resolving it.
    expect(Product::withoutTenancy(fn () => Product::withTrashed()->find($product->id)))->not->toBeNull()
        ->and($product->fresh()->trashed())->toBeFalse()
        ->and($order->fresh()->items[0]['product_id'])->toBe($product->id);
});

it('does not archive a product belonging to another store', function (): void {
    [, $storeA] = pclAWorkspace('Archive Org A');
    [$ownerB, $storeB] = pclAWorkspace('Archive Org B');
    $productA = pclAProduct($storeA, 'ARCH-FOREIGN-1');

    $response = $this->actingAs($ownerB)->postJson('/dashboard/products/bulk/archive', [
        'product_ids' => [$productA->id],
    ])->assertOk();

    expect($response->json('summary.matched'))->toBe(0);
    expect($productA->fresh()->status)->toBe('active');
});
