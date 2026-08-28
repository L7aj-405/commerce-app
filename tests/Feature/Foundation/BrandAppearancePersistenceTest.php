<?php

declare(strict_types=1);

use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\BrandAppearance;

/*
|--------------------------------------------------------------------------
| Brand appearance (primary/accent color, font, radius) persists inside the
| existing stores.settings JSON column (no new migration) under a
| `branding` key, resolved through the single App\Support\BrandAppearance
| helper used by both the settings controller and the global `brand`
| Inertia share.
|--------------------------------------------------------------------------
*/

/** @return array{0: User, 1: Store} */
function bapWorkspace(string $name = 'Brand Persistence Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

it('lets the owner update the store brand primary color and persists it into stores.settings.branding', function (): void {
    [$owner, $store] = bapWorkspace();

    $this->actingAs($owner)
        ->from('/settings/appearance')
        ->patch('/dashboard/settings/branding', [
            'primary' => '#4f46e5',
            'accent' => '#4f46e5',
            'font' => 'inter',
            'radius' => 'pill',
        ])
        ->assertRedirect('/settings/appearance');

    $store->refresh();

    expect($store->settings['branding'])->toBe([
        'primary' => '#4f46e5',
        'accent' => '#4f46e5',
        'font' => 'inter',
        'radius' => 'pill',
    ]);

    expect(BrandAppearance::resolve($store))->toBe([
        'primary' => '#4f46e5',
        'accent' => '#4f46e5',
        'font' => 'inter',
        'radius' => 'pill',
    ]);
});

it('never invents theme state in a second place — the global `brand` Inertia share matches BrandAppearance::resolve()', function (): void {
    [$owner, $store] = bapWorkspace('Shared Brand Store');

    $this->actingAs($owner)->patch('/dashboard/settings/branding', [
        'primary' => '#ea580c', 'accent' => '#ea580c', 'font' => 'compact', 'radius' => 'soft',
    ]);

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('brand.primary', '#ea580c')
            ->where('brand.font', 'compact')
            ->where('brand.radius', 'soft'));
});

it('rejects an invalid hex color', function (): void {
    [$owner] = bapWorkspace('Invalid Hex Store');

    $this->actingAs($owner)
        ->patch('/dashboard/settings/branding', ['primary' => 'notahex'])
        ->assertSessionHasErrors('primary');

    $this->actingAs($owner)
        ->patch('/dashboard/settings/branding', ['primary' => '#12'])
        ->assertSessionHasErrors('primary');
});

it('rejects an unknown font or radius key', function (): void {
    [$owner] = bapWorkspace('Invalid Enum Store');

    $this->actingAs($owner)
        ->patch('/dashboard/settings/branding', ['font' => 'comic-sans'])
        ->assertSessionHasErrors('font');

    $this->actingAs($owner)
        ->patch('/dashboard/settings/branding', ['radius' => 'square'])
        ->assertSessionHasErrors('radius');
});

it('reset restores defaults and clears the stored branding key', function (): void {
    [$owner, $store] = bapWorkspace('Reset Brand Store');

    $this->actingAs($owner)->patch('/dashboard/settings/branding', [
        'primary' => '#e11d48', 'accent' => '#e11d48', 'font' => 'rounded', 'radius' => 'pill',
    ]);
    $store->refresh();
    expect($store->settings['branding']['primary'] ?? null)->toBe('#e11d48');

    $this->actingAs($owner)->patch('/dashboard/settings/branding', ['reset' => true])
        ->assertRedirect();

    $store->refresh();
    expect($store->settings)->not->toHaveKey('branding');
    expect(BrandAppearance::resolve($store))->toBe([
        'primary' => null,
        'accent' => null,
        'font' => BrandAppearance::DEFAULT_FONT,
        'radius' => BrandAppearance::DEFAULT_RADIUS,
    ]);
});

it('preserves other settings (e.g. tax_rate) when branding is saved or reset', function (): void {
    [$owner, $store] = bapWorkspace('Preserve Settings Store');
    $store->update(['settings' => ['tax_rate' => 0.2]]);

    $this->actingAs($owner)->patch('/dashboard/settings/branding', ['primary' => '#2563eb']);
    $store->refresh();
    expect($store->settings['tax_rate'])->toBe(0.2);

    $this->actingAs($owner)->patch('/dashboard/settings/branding', ['reset' => true]);
    $store->refresh();
    expect($store->settings['tax_rate'])->toBe(0.2);
});
