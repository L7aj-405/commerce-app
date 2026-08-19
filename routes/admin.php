<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SuperAdminController;
use App\Http\Controllers\Admin\SupportSessionController;
use Illuminate\Support\Facades\Route;

// ============================================
// SUPER ADMIN PANEL
// ============================================
Route::middleware(['auth', 'super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/',                            [SuperAdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/clients',                     [SuperAdminController::class, 'clients'])->name('clients');
        Route::get('/clients/{user}',              [SuperAdminController::class, 'showClient'])->name('client.show');
        Route::patch('/clients/{user}/suspend',    [SuperAdminController::class, 'suspendClient'])->name('client.suspend');
        Route::patch('/clients/{user}/activate',   [SuperAdminController::class, 'activateClient'])->name('client.activate');

        Route::post('/clients/{user}/stores/{store}/support', [SupportSessionController::class, 'store'])->name('support.start');
        Route::delete('/support', [SupportSessionController::class, 'destroy'])->name('support.end');
    });
