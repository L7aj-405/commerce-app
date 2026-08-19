<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Onboarding\AgencyOnboardingController;
use App\Http\Controllers\Onboarding\MerchantOnboardingController;
use App\Http\Controllers\Onboarding\OnboardingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ============================================
// GUEST
// ============================================
Route::middleware('guest')->group(function () {
    // Registration (Inertia)
    Route::get('/register',  [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');

    // Login, forgot-password and reset-password (Inertia) are all Fortify's
    // own routes now — see FortifyServiceProvider::configureViews(). Do not
    // add Volt::route() registrations for any of these paths here, it would
    // shadow Fortify's GET routes with a second route under the same name.
});

// ============================================
// INVITATION (accessible to both guest and auth — accept handler decides)
// ============================================
Route::get('/invitation/{token}',  [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invitation/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');

// ============================================
// AUTHENTICATED
// ============================================
Route::middleware('auth')->group(function () {
    // Explicit logout (covers both standard and PIN-based login flows)
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    })->name('logout');

    // Onboarding (must complete before reaching the dashboard). show()/complete()
    // are the original single-step flow — kept exactly as-is for backward
    // compatibility (AgencyWorkspaceTest posts to it directly). The mode-select
    // screen it now renders links into the richer step-by-step flows below.
    Route::get('/onboarding',  [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    Route::prefix('onboarding/merchant')->name('onboarding.merchant.')->group(function () {
        Route::get('/',              [MerchantOnboardingController::class, 'show'])->name('show');
        Route::post('/organization', [MerchantOnboardingController::class, 'storeOrganization'])->name('organization');
        Route::post('/store',        [MerchantOnboardingController::class, 'storeStore'])->name('store');
        Route::post('/warehouses',   [MerchantOnboardingController::class, 'storeWarehouses'])->name('warehouses');
        Route::post('/setup',        [MerchantOnboardingController::class, 'storeSetup'])->name('setup');
        Route::post('/complete',     [MerchantOnboardingController::class, 'complete'])->name('complete');
    });

    Route::prefix('onboarding/agency')->name('onboarding.agency.')->group(function () {
        Route::get('/',                  [AgencyOnboardingController::class, 'show'])->name('show');
        Route::post('/organization',     [AgencyOnboardingController::class, 'storeOrganization'])->name('organization');
        Route::post('/services',         [AgencyOnboardingController::class, 'storeServices'])->name('services');
        Route::post('/warehouses',       [AgencyOnboardingController::class, 'storeWarehouses'])->name('warehouses');
        Route::post('/client',           [AgencyOnboardingController::class, 'storeClient'])->name('client');
        Route::post('/client/warehouse', [AgencyOnboardingController::class, 'assignClientWarehouse'])->name('client.warehouse');
        Route::post('/client/services',  [AgencyOnboardingController::class, 'storeClientServices'])->name('client.services');
        Route::post('/client/setup',     [AgencyOnboardingController::class, 'storeClientSetup'])->name('client.setup');
        Route::post('/complete',         [AgencyOnboardingController::class, 'complete'])->name('complete');
    });

    // verify-email and confirm-password (Inertia) are Fortify's own routes —
    // see FortifyServiceProvider::configureViews(). Do not re-add
    // Volt::route() here, it would shadow /email/verify and
    // /user/confirm-password with a second route under the same name.
});
