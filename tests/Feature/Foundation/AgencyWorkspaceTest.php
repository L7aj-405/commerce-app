<?php

declare(strict_types=1);

use App\Models\AgencyClientRelationship;
use App\Models\Organization;
use App\Models\OrganizationServiceAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\OrganizationProvisioner;

it('onboards an agency without forcing a fake tenant store', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);

    $this->actingAs($user)->post('/onboarding', [
        'account_mode' => 'agency',
        'store_name' => 'Atlas Fulfillment',
        'business_type' => null,
        'country' => 'MA',
        'currency' => 'MAD',
        'platforms' => [],
    ])->assertRedirect('/agency/clients');

    $agency = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    expect($agency->type)->toBe(Organization::TYPE_AGENCY)
        ->and(Store::query()->where('organization_id', $agency->id)->count())->toBe(0);
});

it('keeps each agency client in an independent organization while allowing agency operations', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($user, 'Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);

    $clientA = $service->createClient($agency, $user, ['client_name'=>'Client A','brand_name'=>'Brand A','country'=>'MA','currency'=>'MAD']);
    $clientB = $service->createClient($agency, $user, ['client_name'=>'Client B','brand_name'=>'Brand B','country'=>'MA','currency'=>'MAD']);

    expect($clientA->id)->not->toBe($clientB->id)
        ->and($clientA->type)->toBe(Organization::TYPE_CLIENT)
        ->and($clientA->owner_user_id)->toBeNull()
        ->and($user->canOperateClientOrganization($clientA))->toBeTrue()
        ->and(AgencyClientRelationship::query()->count())->toBe(2);
});

it('assigns an agency warehouse to selected clients without changing stock ownership semantics', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($user, 'Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);
    $client = $service->createClient($agency, $user, ['client_name'=>'Client A','brand_name'=>'Brand A','country'=>'MA','currency'=>'MAD']);
    $warehouse = $service->createAgencyWarehouse($agency, $user, ['name'=>'Casa Hub','city'=>'Casablanca','country'=>'MA']);

    $service->assignWarehouse($agency, $client, $warehouse, $user);

    expect($warehouse->owner_organization_id)->toBe($agency->id)
        ->and($warehouse->operator_organization_id)->toBe($agency->id)
        ->and($client->accessibleWarehouses()->whereKey($warehouse->id)->exists())->toBeTrue()
        ->and($client->stores->first()->warehouses()->whereKey($warehouse->id)->exists())->toBeTrue();
});

it('delegates remote services but keeps physical fulfillment tied to warehouse operation', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($user, 'Agency', Organization::TYPE_AGENCY);
    $service = app(AgencyWorkspaceService::class);
    $client = $service->createClient($agency, $user, ['client_name'=>'Client A','brand_name'=>'Brand A','country'=>'MA','currency'=>'MAD']);

    $service->assignService($agency, $client, OrganizationServiceAssignment::SERVICE_CONFIRMATION, 'agency', $user);

    $assignment = OrganizationServiceAssignment::query()->where('client_organization_id',$client->id)->where('service_code','confirmation')->firstOrFail();
    expect($assignment->operator_organization_id)->toBe($agency->id)
        ->and(OrganizationServiceAssignment::SERVICE_CODES)->not->toContain('packing')
        ->and(OrganizationServiceAssignment::SERVICE_CODES)->not->toContain('picking');
});
