<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

function bpWorkspace(string $name = 'Bulk Publish Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function bpWooConnection(Store $store, string $domain = 'bp-woo.example.com'): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'woocommerce',
        'status' => 'active',
        'api_url' => "https://{$domain}",
        'consumer_key' => 'ck_test',
        'consumer_secret' => 'cs_test',
    ]));
}

function bpProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => "Bulk Product {$sku}", 'sku' => $sku, 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
}

function bpListing(Product $product, PlatformConnection $connection, string $externalId): ProductChannelListing
{
    return ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connection->id, 'external_product_id' => $externalId, 'sync_status' => 'synced',
    ]));
}

it('requires product_ids and connection_ids for bulk publish', function (): void {
    [$owner] = bpWorkspace();

    $this->actingAs($owner)->postJson('/dashboard/products/bulk-publish', [
        'product_ids' => [],
        'connection_ids' => ['x'],
    ])->assertStatus(422);

    $this->actingAs($owner)->postJson('/dashboard/products/bulk-publish', [
        'product_ids' => ['x'],
        'connection_ids' => [],
    ])->assertStatus(422);
});

it('bulk publishes only the explicitly selected products', function (): void {
    [$owner, $store] = bpWorkspace();
    $woo = bpWooConnection($store);

    $selected = bpProduct($store, 'BULK-SELECTED-1');
    bpListing($selected, $woo, 'bulk-selected-1');

    $notSelected = bpProduct($store, 'BULK-NOT-SELECTED-1');
    $notSelectedListing = bpListing($notSelected, $woo, 'bulk-not-selected-1');

    Http::fake(['bp-woo.example.com/*' => Http::response(['id' => 'bulk-selected-1'], 200)]);

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk-publish', [
        'product_ids' => [$selected->id],
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('summary.products'))->toBe(1)
        ->and(collect($response->json('results'))->pluck('product_id')->all())->toBe([$selected->id]);

    // The unselected product's listing was never touched.
    $fresh = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($notSelectedListing->id));
    expect($fresh->sync_status)->toBe('synced');
});

it('bulk publishes only to the explicitly selected connections', function (): void {
    [$owner, $store] = bpWorkspace();
    $woo = bpWooConnection($store);
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => 'bp-shopify.myshopify.com', 'access_token' => 'shpat_test',
    ]));

    $product = bpProduct($store, 'BULK-CONN-1');
    bpListing($product, $woo, 'bulk-conn-1-woo');
    bpListing($product, $shopify, 'bulk-conn-1-shopify');

    Http::fake([
        'bp-woo.example.com/*' => Http::response(['id' => 'bulk-conn-1-woo'], 200),
        'bp-shopify.myshopify.com/*' => Http::response(['id' => 'bulk-conn-1-shopify'], 200),
    ]);

    $this->actingAs($owner)->postJson('/dashboard/products/bulk-publish', [
        'product_ids' => [$product->id],
        'connection_ids' => [$woo->id],
    ])->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'bp-woo.example.com'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'bp-shopify.myshopify.com'));
});

it('skips unlinked products in a bulk publish and reports them in the summary', function (): void {
    [$owner, $store] = bpWorkspace();
    $woo = bpWooConnection($store);

    $linked = bpProduct($store, 'BULK-LINKED-1');
    bpListing($linked, $woo, 'bulk-linked-1');

    $unlinked = bpProduct($store, 'BULK-UNLINKED-1');
    // No listing for $unlinked.

    Http::fake(['bp-woo.example.com/*' => Http::response(['id' => 'bulk-linked-1'], 200)]);

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk-publish', [
        'product_ids' => [$linked->id, $unlinked->id],
        'connection_ids' => [$woo->id],
    ])->assertOk();

    expect($response->json('summary.succeeded'))->toBe(1)
        ->and($response->json('summary.skipped'))->toBe(1);

    $skippedRow = collect($response->json('results'))->firstWhere('product_id', $unlinked->id);
    expect($skippedRow['status'])->toBe('skipped')
        ->and($skippedRow['message'])->toBe('not_linked_to_channel');
});

it('does not bulk publish a product belonging to another store even if its id is included', function (): void {
    [, $storeA] = bpWorkspace('Bulk Org A Store');
    [$ownerB, $storeB] = bpWorkspace('Bulk Org B Store');

    $wooB = bpWooConnection($storeB, 'bulk-store-b.example.com');
    $productA = bpProduct($storeA, 'BULK-FOREIGN-1');

    Http::fake(['*' => Http::response(['id' => 'x'], 200)]);

    $response = $this->actingAs($ownerB)->postJson('/dashboard/products/bulk-publish', [
        'product_ids' => [$productA->id],
        'connection_ids' => [$wooB->id],
    ])->assertOk();

    // Store A's product is simply excluded from the scoped set — never published.
    expect($response->json('summary.products'))->toBe(0)
        ->and($response->json('results'))->toBe([]);
});
