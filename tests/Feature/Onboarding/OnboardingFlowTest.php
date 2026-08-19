<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationProvisioner;

it('shows the account-mode question first for a fresh user', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);

    $this->actingAs($user)
        ->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Onboarding/ModeSelect')
            ->has('accountModes', 2));
});

it('redirects a user who already completed onboarding straight to the dashboard', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);

    $this->actingAs($user)->get('/onboarding')->assertRedirect(route('dashboard'));
});

it('resumes a merchant who started but did not finish onboarding', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    app(OrganizationProvisioner::class)->createOwnedOrganization($user, 'Resuming Merchant', Organization::TYPE_MERCHANT);

    $this->actingAs($user)->get('/onboarding')->assertRedirect(route('onboarding.merchant.show'));
});

it('resumes an agency owner who started but did not finish onboarding', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);
    app(OrganizationProvisioner::class)->createOwnedOrganization($user, 'Resuming Agency', Organization::TYPE_AGENCY);

    $this->actingAs($user)->get('/onboarding')->assertRedirect(route('onboarding.agency.show'));
});

it('shows the correct next step when returning to the merchant wizard mid-flow', function (): void {
    $user = User::factory()->create(['onboarding_completed_at' => null]);

    $this->actingAs($user)->get('/onboarding/merchant')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Onboarding/Merchant')
            ->where('progress.organization', null)
            ->where('progress.store', null));

    $this->actingAs($user)->post('/onboarding/merchant/organization', [
        'name' => 'Mid Flow Co', 'country' => 'MA', 'currency' => 'MAD',
    ])->assertRedirect();

    $this->actingAs($user)->get('/onboarding/merchant')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('progress.organization.name', 'Mid Flow Co')
            ->where('progress.store', null));
});
