<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformConnection;
use App\Models\ProductChannelListing;
use App\Models\Store;
use App\Models\User;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store} */
function csWorkspace(string $name = 'Connection Scope Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function csWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('does not let a merchant view another organization\'s connection profile', function (): void {
    [, $storeA] = csWorkspace('Scope Merchant Org A');
    [$ownerB] = csWorkspace('Scope Merchant Org B');
    $connectionA = csWoo($storeA, 'scope1-woo.example.com');

    $this->actingAs($ownerB)
        ->get("/dashboard/integrations/connections/{$connectionA->id}")
        ->assertNotFound();
});

it('does not let a merchant reset another organization\'s connection mappings', function (): void {
    [, $storeA] = csWorkspace('Scope Merchant Reset Org A');
    [$ownerB] = csWorkspace('Scope Merchant Reset Org B');
    $connectionA = csWoo($storeA, 'scope2-woo.example.com');
    $product = \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $storeA->id, 'name' => 'Scope Product', 'sku' => 'SCOPE-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connectionA->id, 'external_product_id' => 'woo-scope-1', 'sync_status' => 'synced',
    ]));

    $this->actingAs($ownerB)
        ->postJson("/dashboard/integrations/connections/{$connectionA->id}/reset-product-mappings")
        ->assertNotFound();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->not->toBeNull();
});

it('lets an agency operator manage a client store\'s connection while that client is the active store', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Scope Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $client = $service->createClient($agency, $owner, [
        'client_name' => 'Scope Client', 'brand_name' => 'Scope Brand', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $clientStore = $client->stores->first();
    $connection = csWoo($clientStore, 'scope3-woo.example.com');

    $this->actingAs($owner)
        ->withSession(['store_id' => $clientStore->id])
        ->get("/dashboard/integrations/connections/{$connection->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('connection.id', $connection->id));
});

it('does not let an agency operator reset a DIFFERENT client\'s connection while another client is active', function (): void {
    $owner = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Scope Agency Two', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $owner, [
        'client_name' => 'Scope Client A', 'brand_name' => 'Scope Brand A', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $clientB = $service->createClient($agency, $owner, [
        'client_name' => 'Scope Client B', 'brand_name' => 'Scope Brand B', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $storeA = $clientA->stores->first();
    $storeB = $clientB->stores->first();

    $connectionA = csWoo($storeA, 'scope4-woo.example.com');
    $product = \App\Models\Product::withoutTenancy(fn () => \App\Models\Product::create([
        'store_id' => $storeA->id, 'name' => 'Scope Client Product', 'sku' => 'SCOPE-CLIENT-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $connectionA->id, 'external_product_id' => 'woo-scope-client-1', 'sync_status' => 'synced',
    ]));

    // Client B is the active store — pasting client A's connection id directly must not work.
    $this->actingAs($owner)
        ->withSession(['store_id' => $storeB->id])
        ->postJson("/dashboard/integrations/connections/{$connectionA->id}/reset-product-mappings")
        ->assertNotFound();

    expect(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->not->toBeNull();
});
