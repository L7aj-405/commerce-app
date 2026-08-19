<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('loads the profile settings page as Inertia', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Profile'));
});

it('loads the appearance settings page as Inertia', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/appearance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Appearance'));
});

it('loads the security settings page as Inertia', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Security'));
});

it('does not resolve any settings route to Volt/Livewire', function (): void {
    foreach (['profile.edit', 'appearance.edit', 'security.edit'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        $action = $route->getActionName();

        expect($action)->not->toContain('VoltManager')
            ->and($action)->toContain('Settings\\SettingsController');
    }
});
