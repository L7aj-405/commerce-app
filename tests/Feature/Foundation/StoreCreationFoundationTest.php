<?php

declare(strict_types=1);

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Str;

function storeCreationOwner(string $name, string $type = Organization::TYPE_MERCHANT): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, $type);

    return compact('user', 'organization');
}

/**
 * A real Store + membership under $organization, so the owner can actually
 * reach /dashboard/* at all — EnsureCanAccessDashboard requires an active
 * store (getActiveStore() !== null) before any dashboard route, including
 * "Add store", is reachable. Mirrors what onboarding already produces.
 */
function storeCreationSeedStore(Organization $organization, User $user, string $name = 'Seed Store'): Store
{
    $store = Store::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
        'type' => StoreType::Online->value,
        'country' => 'MA',
        'currency' => 'MAD',
        'status' => StoreStatus::Active->value,
        'settings' => [],
    ]);

    $store->ensureDefaultRoles();

    StoreMember::create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'role' => 'store_admin',
        'store_role_id' => $store->adminRole()?->id,
        'is_active' => true,
        'joined_at' => now(),
    ]);

    return $store->refresh();
}

it('lets a merchant owner create a store under their current merchant organization', function (): void {
    ['user' => $user, 'organization' => $organization] = storeCreationOwner('Acme Merchant');
    storeCreationSeedStore($organization, $user);

    $response = $this->actingAs($user)->post('/dashboard/stores', [
        'store_name' => 'Acme Flagship',
        'store_type' => 'online',
        'country'    => 'MA',
        'currency'   => 'MAD',
    ]);

    $response->assertRedirect(route('dashboard.stores.index'));

    $store = Store::query()->where('organization_id', $organization->id)->where('name', 'Acme Flagship')->firstOrFail();
    expect($store->organization_id)->toBe($organization->id);
});

it('does not create a new Organization when a store is added', function (): void {
    ['user' => $user, 'organization' => $organization] = storeCreationOwner('No Silent Org Co');
    storeCreationSeedStore($organization, $user);

    expect(Organization::query()->count())->toBe(1);

    $this->actingAs($user)->post('/dashboard/stores', [
        'store_name' => 'No Silent Org Store',
        'store_type' => 'online',
        'country'    => 'MA',
        'currency'   => 'MAD',
    ]);

    expect(Organization::query()->count())->toBe(1);
});

it('rejects direct store creation under an agency organization', function (): void {
    ['user' => $owner, 'organization' => $agency] = storeCreationOwner('Fulfil Agency', Organization::TYPE_AGENCY);

    // Agencies never get a store of their own — the owner reaches the
    // dashboard via a client's store instead, same as in production.
    app(AgencyWorkspaceService::class)->createClient($agency, $owner, [
        'client_name' => 'Guard Client', 'brand_name' => 'Guard Brand', 'country' => 'MA', 'currency' => 'MAD',
    ]);

    $response = $this->actingAs($owner)->post('/dashboard/stores', [
        'organization_id' => $agency->id,
        'store_name'       => 'Fake Agency Store',
        'store_type'       => 'online',
        'country'          => 'MA',
        'currency'         => 'MAD',
    ]);

    $response->assertSessionHasErrors('organization_id');
    expect(Store::query()->where('organization_id', $agency->id)->count())->toBe(0);
});

it('lets an agency operator create a store under a client organization it manages', function (): void {
    ['user' => $owner, 'organization' => $agency] = storeCreationOwner('Client Store Agency', Organization::TYPE_AGENCY);
    $client = app(AgencyWorkspaceService::class)->createClient($agency, $owner, [
        'client_name' => 'Client Co', 'brand_name' => 'Client Co Brand', 'country' => 'MA', 'currency' => 'MAD',
    ]);

    expect($owner->canManageOrganization($client))->toBeTrue();

    $response = $this->actingAs($owner)->post('/dashboard/stores', [
        'organization_id' => $client->id,
        'store_name'       => 'Client Co Second Brand',
        'store_type'       => 'physical',
        'country'          => 'MA',
        'currency'         => 'MAD',
    ]);

    $response->assertRedirect(route('dashboard.stores.index'));

    $store = Store::query()->where('organization_id', $client->id)->where('name', 'Client Co Second Brand')->firstOrFail();
    expect($store->organization_id)->toBe($client->id);
});

it('does not let a user create a store under an organization they do not manage', function (): void {
    ['user' => $userA, 'organization' => $orgA] = storeCreationOwner('Org A');
    storeCreationSeedStore($orgA, $userA);
    ['organization' => $orgB] = storeCreationOwner('Org B');

    $response = $this->actingAs($userA)->post('/dashboard/stores', [
        'organization_id' => $orgB->id,
        'store_name'       => 'Trespassing Store',
        'store_type'       => 'online',
        'country'          => 'MA',
        'currency'         => 'MAD',
    ]);

    $response->assertForbidden();
    expect(Store::query()->where('organization_id', $orgB->id)->count())->toBe(0);
});

it('stores Store.type as online/physical/hybrid correctly', function (): void {
    ['user' => $user, 'organization' => $organization] = storeCreationOwner('Type Semantics Co');
    storeCreationSeedStore($organization, $user);

    $this->actingAs($user)->post('/dashboard/stores', [
        'store_name' => 'Hybrid Shop',
        'store_type' => 'hybrid',
        'country'    => 'MA',
        'currency'   => 'MAD',
    ]);

    $store = Store::query()->where('name', 'Hybrid Shop')->firstOrFail();
    expect($store->type)->toBe(StoreType::Hybrid);
});

it('keeps Store.business_type as a nullable industry/category, separate from store type', function (): void {
    ['user' => $user, 'organization' => $organization] = storeCreationOwner('Industry Semantics Co');
    storeCreationSeedStore($organization, $user);

    $this->actingAs($user)->post('/dashboard/stores', [
        'store_name' => 'No Industry Shop',
        'store_type' => 'online',
        'country'    => 'MA',
        'currency'   => 'MAD',
    ]);

    $withoutIndustry = Store::query()->where('name', 'No Industry Shop')->firstOrFail();
    expect($withoutIndustry->business_type)->toBeNull()
        ->and($withoutIndustry->type)->toBe(StoreType::Online);

    $this->actingAs($user)->post('/dashboard/stores', [
        'store_name'    => 'Retail Industry Shop',
        'store_type'    => 'physical',
        'business_type' => 'retail',
        'country'       => 'MA',
        'currency'      => 'MAD',
    ]);

    $withIndustry = Store::query()->where('name', 'Retail Industry Shop')->firstOrFail();
    expect($withIndustry->business_type)->toBe('retail')
        ->and($withIndustry->type)->toBe(StoreType::Physical);
});
