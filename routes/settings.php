<?php

use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [SettingsController::class, 'profile'])->name('profile.edit');
    Route::patch('settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::delete('settings/profile', [SettingsController::class, 'destroyAccount'])->name('settings.profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/appearance', [SettingsController::class, 'appearance'])->name('appearance.edit');

    Route::get('settings/security', [SettingsController::class, 'security'])
        ->middleware(
            // Same conditional guard the previous Volt route carried — only
            // require a fresh password confirmation when 2FA's own
            // 'confirmPassword' option is actually enabled.
            Features::canManageTwoFactorAuthentication() && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
                ? ['password.confirm']
                : []
        )
        ->name('security.edit');

    Route::put('settings/security/password', [SettingsController::class, 'updatePassword'])->name('settings.security.password');
});
