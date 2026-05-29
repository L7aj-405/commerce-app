<?php

use App\Livewire\Dashboard;
use App\Livewire\Orders\OrderDetails;
use App\Livewire\Orders\OrderIndex;
use App\Livewire\Products\ProductCreationWizard;
use App\Livewire\Products\ProductEditWizard;
use App\Livewire\Products\ProductIndex;
use App\Livewire\Stores\Connections\ConnectionIndex;
use App\Livewire\Stores\Connections\ConnectPlatform;
use App\Livewire\Stores\Connections\EditConnection;
use App\Livewire\Stores\CreateStore;
use App\Livewire\Stores\EditStore;
use App\Livewire\Stores\Settings\WhatsappSettings;
use App\Livewire\Stores\Settings\WhatsAppTemplates;
use App\Livewire\Stores\StoreIndex;
use App\Livewire\Stores\WhatsAppSetupWizard;
use App\Livewire\Stores\WhatsAppUserSetup;
use App\Livewire\Warehouses\WarehouseCreate;
use App\Livewire\Warehouses\WarehouseEdit;
use App\Livewire\Warehouses\WarehouseIndex;
use Illuminate\Support\Facades\Route;

// ============================================
// PUBLIC ROUTES
// ============================================

Route::view('/', 'welcome')->name('home');

// ============================================
// AUTHENTICATED ROUTES
// ============================================

Route::middleware(['auth', 'verified', 'check.status'])->group(function () {

    // ==========================================
    // DASHBOARD
    // ==========================================
    Route::get('/dashboard', Dashboard::class)
        ->name('dashboard');

    // ==========================================
    // PROFILE
    // ==========================================
    Route::get('/profile', fn () => view('profile'))
        ->name('profile');

    // ==========================================
    // ORDERS
    // ==========================================
    Route::get('/orders', OrderIndex::class)
        ->name('orders.index');
    
    Route::get('/orders/{order}', OrderDetails::class)
        ->name('orders.show');

    // ==========================================
    // STORES
    // ==========================================
    Route::get('/stores', StoreIndex::class)
        ->name('stores.index');
    
    Route::get('/stores/create', CreateStore::class)
        ->name('stores.create');
    
    Route::get('/stores/{store}/edit', EditStore::class)
        ->name('stores.edit');

    // ==========================================
    // STORE SETTINGS & CONNECTIONS
    // ==========================================
    Route::prefix('stores/{store}')->group(function () {
        
        // WhatsApp Settings
        Route::get('/settings/whatsapp', WhatsappSettings::class)
            ->name('stores.settings.whatsapp');

        // WhatsApp Message Templates
        Route::get('/settings/whatsapp/templates', WhatsAppTemplates::class)
            ->name('stores.settings.whatsapp.templates');

        // WhatsApp Setup Wizard (method chooser)
        Route::get('/whatsapp/setup', WhatsAppSetupWizard::class)
            ->name('stores.whatsapp.setup');

        // WhatsApp User App setup (user's own Meta app credentials)
        Route::get('/whatsapp/connect', WhatsAppUserSetup::class)
            ->name('stores.whatsapp.connect');

        // Connections
        Route::get('/connections', ConnectionIndex::class)
            ->name('stores.connections.index');
        
        Route::get('/connections/connect', ConnectPlatform::class)
            ->name('stores.connections.create');
        
        Route::get('/connections/{connection}/edit', EditConnection::class)
            ->name('stores.connections.edit');

        // ======================================
        // PRODUCTS (NEW)
        // ======================================
        Route::get('/products', ProductIndex::class)
            ->name('stores.products.index');
        
        Route::get('/products/create', ProductCreationWizard::class)
            ->name('stores.products.create');
        
        Route::get('/products/{product}/edit', ProductEditWizard::class)
            ->name('stores.products.edit');
    });

    // ==========================================
    // WAREHOUSES (NEW)
    // ==========================================
    Route::get('/warehouses', WarehouseIndex::class)
        ->name('warehouses.index');
    
    Route::get('/warehouses/create', WarehouseCreate::class)
        ->name('warehouses.create');
    
    Route::get('/warehouses/{warehouse}/edit', WarehouseEdit::class)
        ->name('warehouses.edit');
});

// ==========================================
// META OAUTH FLOW
// ==========================================

// Callback is public — Meta redirects here after Facebook login
Route::get('/auth/meta/callback', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'handleMetaCallback'])
    ->name('auth.meta.callback');

// Account and number selectors require auth (transient OAuth session flow)
Route::middleware(['auth'])->group(function () {
    Route::get('/auth/meta/select-account', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'showAccountSelector'])
        ->name('auth.meta.select-account');
    Route::post('/auth/meta/select-account', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'handleAccountSelection'])
        ->name('auth.meta.select-account.post');

    Route::get('/auth/meta/select-number', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'showNumberSelector'])
        ->name('auth.meta.select-number');
    Route::post('/auth/meta/select-number', [\App\Http\Controllers\Auth\MetaOAuthController::class, 'handleNumberSelection'])
        ->name('auth.meta.select-number.post');
});

// ==========================================
// AUTH ROUTES
// ==========================================
require __DIR__.'/auth.php';