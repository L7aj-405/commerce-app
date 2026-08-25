<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Route;

function agencyNavWorkspace(string $name = 'Agency Nav Workspace'): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_AGENCY);

    return [$user, $agency];
}

it('routes every agency nav destination to a real, named route', function (): void {
    foreach (['agency.clients.index', 'agency.clients.show', 'agency.warehouses.index'] as $name) {
        expect(Route::has($name))->toBeTrue("Expected route [{$name}] to exist.");
    }
});

it('renders the agency clients page on AgencyLayout with clients and warehouses reachable', function (): void {
    [$user] = agencyNavWorkspace();

    $this->actingAs($user)->get('/agency/clients')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agency/Clients')
            ->where('agency.name', fn ($name) => is_string($name) && $name !== ''));

    $this->actingAs($user)->get('/agency/warehouses')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agency/Warehouses'));
});

it('shows client org, warehouses and service assignments on the client detail page', function (): void {
    [$user, $agency] = agencyNavWorkspace('Agency Client Detail Workspace');
    $client = app(AgencyWorkspaceService::class)->createClient($agency, $user, [
        'client_name' => 'Client A', 'brand_name' => 'Brand A', 'country' => 'MA', 'currency' => 'MAD',
    ]);

    $this->actingAs($user)->get("/agency/clients/{$client->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Agency/ClientShow')
            ->has('client')
            ->has('warehouses')
            ->has('services'));
});

it('lets an agency owner open a client store dashboard', function (): void {
    [$user, $agency] = agencyNavWorkspace('Agency Open Dashboard Workspace');
    $client = app(AgencyWorkspaceService::class)->createClient($agency, $user, [
        'client_name' => 'Client B', 'brand_name' => 'Brand B', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $store = $client->stores()->firstOrFail();

    $this->actingAs($user)->post("/agency/clients/{$client->id}/stores/{$store->id}/open")
        ->assertRedirect('/dashboard');
});

it('does not expose agency client management routes to a plain merchant user', function (): void {
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Plain Merchant Workspace');

    $this->actingAs($owner)->get('/agency/clients')->assertForbidden();
});
