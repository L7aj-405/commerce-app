<?php

use Illuminate\Support\Facades\Route;

// ============================================
// PUBLIC
// ============================================
Route::get('/', fn () => \Inertia\Inertia::render('Welcome'))->name('home');

// ============================================
// META OAUTH (preserved — Meta redirects here)
// ============================================
Route::get('/auth/meta/callback', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'handleMetaCallback'])
    ->name('auth.meta.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/auth/meta/select-account',  [\App\Http\Controllers\Auth\MetaOAuthController::class, 'showAccountSelector'])->name('auth.meta.select-account');
    Route::post('/auth/meta/select-account', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'handleAccountSelection'])->name('auth.meta.select-account.post');
    Route::get('/auth/meta/select-number',   [\App\Http\Controllers\Auth\MetaOAuthController::class, 'showNumberSelector'])->name('auth.meta.select-number');
    Route::post('/auth/meta/select-number',  [\App\Http\Controllers\Auth\MetaOAuthController::class, 'handleNumberSelection'])->name('auth.meta.select-number.post');
});

// ============================================
// LEGACY LIVEWIRE ROUTES
// (kept until migrated to Inertia controllers under dashboard.php)
// ============================================
Route::middleware(['auth', \App\Http\Middleware\ResolveTenant::class, 'verified', 'check.status', 'onboarding_complete', 'can_dashboard'])->group(function () {

    // /profile → user settings (Volt)
    Route::redirect('/profile', '/settings/profile')->name('profile');

    // Legacy Livewire URLs → redirect to Inertia equivalents (named routes preserved
    // so sidebar/email/external links still work).
    Route::get('/orders',         fn () => redirect('/dashboard/orders'))->name('orders.index');
    Route::get('/orders/{order}', fn ($order) => redirect("/dashboard/orders/{$order}"))->name('orders.show');

    Route::get('/stores',              fn () => redirect('/dashboard/stores'))->name('stores.index');
    Route::get('/stores/create',       fn () => redirect('/dashboard/stores/create'))->name('stores.create');
    Route::get('/stores/{store}/edit', fn ($store) => redirect("/dashboard/stores/{$store}/edit"))->name('stores.edit');

    Route::get('/warehouses',                  fn () => redirect('/dashboard/warehouses'))->name('warehouses.index');
    Route::get('/warehouses/create',           fn () => redirect('/dashboard/warehouses/create'))->name('warehouses.create');
    Route::get('/warehouses/{warehouse}/edit', fn ($warehouse) => redirect("/dashboard/warehouses/{$warehouse}/edit"))->name('warehouses.edit');

    // Store sub-routes — variant wizards + WhatsApp/Meta OAuth wizards stay as Livewire
    // (multi-step flows with active OAuth business logic; risky to rebuild).
    Route::prefix('stores/{store}')->group(function () {
        // Products: creation/edit wizard migrated to Inertia (Option B2) —
        // legacy Livewire ProductCreationWizard/ProductEditWizard are now
        // unrouted (kept on disk until confirmed fully dead).
        Route::get('/products',                fn ($store) => redirect('/dashboard/products'))->name('stores.products.index');
        Route::get('/products/create',         fn ($store) => redirect('/dashboard/products/create'))->name('stores.products.create');
        Route::get('/products/{product}/edit', fn ($store, $product) => redirect("/dashboard/products/{$product}/edit"))->name('stores.products.edit');

        // WhatsApp / Meta OAuth wizard (kept — handles Facebook OAuth callback flow)
        Route::get('/settings/whatsapp',           \App\Livewire\Stores\Settings\WhatsappSettings::class)->name('stores.settings.whatsapp');
        Route::get('/settings/whatsapp/templates', \App\Livewire\Stores\Settings\WhatsAppTemplates::class)->name('stores.settings.whatsapp.templates');
        Route::get('/whatsapp/setup',              \App\Livewire\Stores\WhatsAppSetupWizard::class)->name('stores.whatsapp.setup');
        Route::get('/whatsapp/connect',            \App\Livewire\Stores\WhatsAppUserSetup::class)->name('stores.whatsapp.connect');
    });
});

// ============================================
// THEMED ROUTE GROUPS
// ============================================
require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/agency.php';
require __DIR__.'/dashboard.php';
require __DIR__.'/pos.php';
require __DIR__.'/settings.php';
