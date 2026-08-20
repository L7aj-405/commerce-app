<?php

declare(strict_types=1);

use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;

function importEntryPointWorkspace(string $name = 'Import Entry Point Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('loads the products index page with the connections contract the Import button reads', function (): void {
    [$owner] = importEntryPointWorkspace();

    $this->actingAs($owner)
        ->get('/dashboard/products')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard/Products/Index')
            ->has('connections'));
});

it('omits Shopify from active connections when the store has not connected it, so the Import flow prompts to connect first', function (): void {
    [$owner] = importEntryPointWorkspace();

    $this->actingAs($owner)
        ->get('/dashboard/products')
        ->assertInertia(fn ($page) => $page
            ->where('connections', fn ($connections) => collect($connections)->doesntContain(fn ($c) => $c['platform'] === 'shopify')));
});

it('includes an active Shopify connection once connected, so the Import flow offers it directly', function (): void {
    [$owner, $store] = importEntryPointWorkspace();

    PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'shopify',
        'connection_method' => 'webhook',
        'shop_domain' => 'connected-shop.myshopify.com',
        'status' => 'active',
        'webhook_status' => 'verified',
    ]));

    $this->actingAs($owner)
        ->get('/dashboard/products')
        ->assertInertia(fn ($page) => $page
            ->where('connections', fn ($connections) => collect($connections)->contains(fn ($c) => $c['platform'] === 'shopify')));
});
