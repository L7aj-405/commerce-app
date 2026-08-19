<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;

function completeMerchantOrganizationStep(User $user, array $overrides = []): void
{
    test()->actingAs($user)->post('/onboarding/merchant/organization', array_merge([
        'name' => 'Acme Retail', 'country' => 'MA', 'currency' => 'MAD', 'phone' => '+212600000000',
    ], $overrides))->assertRedirect();
}

function completeMerchantStoreStep(User $user, array $overrides = []): void
{
    test()->actingAs($user)->post('/onboarding/merchant/store', array_merge([
        'name' => 'Acme Store', 'business_type' => 'online',
    ], $overrides))->assertRedirect();
}

it('starts with the account-mode selection, then creates a merchant organization', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);

    $this->actingAs($user)->get('/onboarding')
        ->assertInertia(fn ($page) => $page->component('Onboarding/ModeSelect'));

    completeMerchantOrganizationStep($user);

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    expect($organization->type)->toBe(Organization::TYPE_MERCHANT)
        ->and($organization->settings['country'])->toBe('MA')
        ->and($organization->settings['currency'])->toBe('MAD');
});

it('creates the first store under the merchant organization', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeMerchantOrganizationStep($user);
    completeMerchantStoreStep($user);

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $store = Store::query()->where('organization_id', $organization->id)->firstOrFail();

    expect($store->name)->toBe('Acme Store')
        ->and($store->type->value)->toBe('online')
        ->and($store->organization_id)->toBe($organization->id);
});

it('creates a default warehouse owned and operated by the merchant organization when chosen', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeMerchantOrganizationStep($user);
    completeMerchantStoreStep($user);

    $this->actingAs($user)->post('/onboarding/merchant/warehouses', ['mode' => 'default'])->assertRedirect();

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id', $organization->id)->firstOrFail());

    expect($warehouse->owner_organization_id)->toBe($organization->id)
        ->and($warehouse->operator_organization_id)->toBe($organization->id)
        ->and($warehouse->is_default)->toBeTrue();

    $store = Store::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($store->warehouses()->whereKey($warehouse->id)->exists())->toBeTrue();
});

it('creates nothing when the merchant has no stock to manage yet', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeMerchantOrganizationStep($user);
    completeMerchantStoreStep($user);

    $this->actingAs($user)->post('/onboarding/merchant/warehouses', ['mode' => 'none'])->assertRedirect();

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    expect(Warehouse::withoutTenancy(fn () => Warehouse::query()->where('owner_organization_id', $organization->id)->count()))->toBe(0);
});

it('lets sales channels stay optional and completes onboarding without Shopify or WooCommerce', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    completeMerchantOrganizationStep($user);
    completeMerchantStoreStep($user);
    $this->actingAs($user)->post('/onboarding/merchant/warehouses', ['mode' => 'none'])->assertRedirect();

    $this->actingAs($user)->post('/onboarding/merchant/setup', [
        'inventory_source' => 'empty',
        'sales_channels'   => [],
    ])->assertRedirect();

    $this->actingAs($user)->post('/onboarding/merchant/complete')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->onboarding_completed_at)->not->toBeNull();

    $organization = Organization::query()->where('owner_user_id', $user->id)->firstOrFail();
    $store = Store::query()->where('organization_id', $organization->id)->firstOrFail();
    expect($store->settings['sales_channels'])->toBe([]);
});
