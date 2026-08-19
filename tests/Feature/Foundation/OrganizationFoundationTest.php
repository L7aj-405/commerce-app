<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\TenantContext;

it('creates an organization and owner membership during onboarding', function (): void {
    $user = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => null,
    ]);

    $this->actingAs($user)
        ->post('/onboarding', [
            'store_name' => 'Atlas Commerce',
            'business_type' => 'retail',
            'country' => 'MA',
            'currency' => 'MAD',
            'phone' => '+212600000000',
            'platforms' => ['woocommerce', 'pos'],
        ])
        ->assertRedirect('/dashboard');

    $store = Store::query()->where('user_id', $user->id)->firstOrFail();
    $organization = Organization::query()->findOrFail($store->organization_id);

    expect($organization->owner_user_id)->toBe($user->id)
        ->and($organization->name)->toBe('Atlas Commerce');

    $this->assertDatabaseHas('organization_members', [
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_OWNER,
        'is_active' => true,
    ]);
});

it('reuses the active manageable organization when creating another store', function (): void {
    $user = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $provisioner = app(OrganizationProvisioner::class);
    $organization = $provisioner->createOwnedOrganization($user, 'North Group');

    $store = Store::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
    ]);

    session()->put('store_id', $store->id);

    $resolved = $provisioner->forNewStore($user, 'Second Brand');

    expect($resolved->is($organization))->toBeTrue();
});

it('keeps store and organization identity together in tenant context', function (): void {
    $context = app(TenantContext::class);

    $context->set('store-01', 'org-01');

    expect($context->storeId())->toBe('store-01')
        ->and($context->organizationId())->toBe('org-01')
        ->and($context->has())->toBeTrue()
        ->and($context->hasOrganization())->toBeTrue();

    $context->runWithout(function () use ($context): void {
        expect($context->storeId())->toBeNull()
            ->and($context->organizationId())->toBeNull();
    });

    expect($context->storeId())->toBe('store-01')
        ->and($context->organizationId())->toBe('org-01');
});
