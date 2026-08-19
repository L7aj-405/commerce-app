<?php

declare(strict_types=1);

use App\Http\Controllers\Pos\CashierAuthController;
use App\Http\Controllers\Pos\CheckoutController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\SessionController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

// ============================================
// POS LOGIN (PIN-based; not Laravel-auth required)
// `pos.guest` redirects already-PIN-authed cashiers back to /pos
// ============================================
Route::middleware('pos.guest')->group(function () {
    Route::get('/pos/login',  [CashierAuthController::class, 'showLogin'])->name('pos.login');
    Route::post('/pos/login', [CashierAuthController::class, 'login'])->name('pos.login.store');

    // First-time cashier PIN enrolment (email + password → choose a PIN).
    Route::post('/pos/setup-pin', [CashierAuthController::class, 'setupPin'])->name('pos.setup-pin');
});

// ============================================
// POS TERMINAL
// Layered gates:
//   `auth`     — Laravel auth (set by Auth::login during PIN check)
//   `can_pos`  — role check (store_admin / manager / cashier)
//   `pos.auth` — PIN session keys (cashier_id + store_id) re-validated
// ============================================
Route::middleware(['auth', ResolveTenant::class, 'pos.auth'])
    ->prefix('pos')
    ->name('pos.')
    ->group(function () {
        Route::get('/',                [PosController::class, 'dashboard'])->name('dashboard');
        Route::get('/products/search', [PosController::class, 'searchProducts'])->name('products.search');
        Route::post('/checkout',       [CheckoutController::class, 'store'])->name('checkout');
        Route::post('/session/open',   [SessionController::class, 'open'])->name('session.open');
        Route::post('/session/close',  [SessionController::class, 'close'])->name('session.close');
        Route::post('/logout',         [CashierAuthController::class, 'logout'])->name('logout');
    });
