<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationServiceAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;

function completeAgencyOrganizationStep(User $user, array $overrides = []): void
{
    test()->actingAs($user)->post('/onboarding/agency/organization', array_merge([
        'name' => 'Atlas Fulfillment', 'country' => 'MA', 'currency' => 'MAD',
    ], $overrides))->assertRedirect();
}

it('creates an agency organization and does not create a fake store for it', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);

    completeAgencyOrganizationStep($user);

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    expect($organization->type)->toBe(Organization::TYPE_AGENCY)
        ->and(Store::query()->where('organization_id', $organization->id)->count())->toBe(0);
});

it('creates an agency warehouse owned and operated by the agency organization', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeAgencyOrganizationStep($user);

    $this->actingAs($user)->post('/onboarding/agency/services', ['services' => ['warehousing']])->assertRedirect();
    $this->actingAs($user)->post('/onboarding/agency/warehouses', [
        'warehouses' => [['name' => 'Casa Hub', 'city' => 'Casablanca']],
    ])->assertRedirect();

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id', $organization->id)->firstOrFail());

    expect($warehouse->owner_organization_id)->toBe($organization->id)
        ->and($warehouse->operator_organization_id)->toBe($organization->id);
});

it('creates a separate Client organization when adding the first client, not a store under the agency', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeAgencyOrganizationStep($user);
    $this->actingAs($user)->post('/onboarding/agency/services', ['services' => []])->assertRedirect();

    $this->actingAs($user)->post('/onboarding/agency/client', [
        'client_name' => 'Client A', 'brand_name' => 'Brand A', 'business_type' => 'online',
        'country' => 'MA', 'currency' => 'MAD',
    ])->assertRedirect();

    $agency = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $client = $agency->clientOrganizations()->firstOrFail();

    expect($client->type)->toBe(Organization::TYPE_CLIENT)
        ->and($client->id)->not->toBe($agency->id);

    $clientStore = Store::query()->where('organization_id', $client->id)->firstOrFail();
    expect($clientStore->name)->toBe('Brand A')
        ->and($clientStore->organization_id)->toBe($client->id)
        ->and($clientStore->organization_id)->not->toBe($agency->id);
});

it('keeps stock ownership with the client when an agency warehouse is assigned to it', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeAgencyOrganizationStep($user);
    $this->actingAs($user)->post('/onboarding/agency/services', ['services' => ['warehousing']])->assertRedirect();
    $this->actingAs($user)->post('/onboarding/agency/warehouses', ['warehouses' => [['name' => 'Shared Hub']]])->assertRedirect();
    $this->actingAs($user)->post('/onboarding/agency/client', [
        'client_name' => 'Client B', 'brand_name' => 'Brand B', 'business_type' => 'online',
        'country' => 'MA', 'currency' => 'MAD',
    ])->assertRedirect();

    $agency = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $client = $agency->clientOrganizations()->firstOrFail();
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id', $agency->id)->firstOrFail());

    $this->actingAs($user)->post('/onboarding/agency/client/warehouse', [
        'mode' => 'assign_agency', 'warehouse_id' => $warehouse->id,
    ])->assertRedirect();

    $warehouse->refresh();
    expect($warehouse->owner_organization_id)->toBe($agency->id)
        ->and($warehouse->operator_organization_id)->toBe($agency->id)
        ->and($client->accessibleWarehouses()->whereKey($warehouse->id)->exists())->toBeTrue();
});

it('sets client service assignments to self or agency, never picking/packing/dispatch', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeAgencyOrganizationStep($user);
    $this->actingAs($user)->post('/onboarding/agency/services', ['services' => []])->assertRedirect();
    $this->actingAs($user)->post('/onboarding/agency/client', [
        'client_name' => 'Client C', 'brand_name' => 'Brand C', 'business_type' => 'online',
        'country' => 'MA', 'currency' => 'MAD',
    ])->assertRedirect();

    $agency = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $client = $agency->clientOrganizations()->firstOrFail();

    $this->actingAs($user)->post('/onboarding/agency/client/services', [
        'assignments' => [
            'confirmation'     => 'agency',
            'customer_support' => 'self',
            'delivery'         => 'agency',
            'picking'          => 'agency',
            'packing'          => 'agency',
            'dispatch'         => 'agency',
        ],
    ])->assertRedirect();

    $assignment = OrganizationServiceAssignment::query()
        ->where('client_organization_id', $client->id)->where('service_code', 'confirmation')->firstOrFail();
    expect($assignment->operator_organization_id)->toBe($agency->id)
        ->and(OrganizationServiceAssignment::query()->where('client_organization_id', $client->id)->whereIn('service_code', ['picking', 'packing', 'dispatch'])->count())->toBe(0)
        ->and(OrganizationServiceAssignment::SERVICE_CODES)->not->toContain('picking')
        ->and(OrganizationServiceAssignment::SERVICE_CODES)->not->toContain('packing')
        ->and(OrganizationServiceAssignment::SERVICE_CODES)->not->toContain('dispatch');
});
