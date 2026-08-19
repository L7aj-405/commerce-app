<?php

declare(strict_types=1);

use App\Http\Controllers\Agency\ClientController;
use App\Http\Controllers\Agency\WarehouseController;
use App\Http\Middleware\EnsureAgencyAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'check.status', 'onboarding_complete', EnsureAgencyAccess::class])
    ->prefix('agency')->name('agency.')->group(function (): void {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::post('/clients/{client}/stores/{store}/open', [ClientController::class, 'open'])->name('clients.open');
        Route::post('/clients/{client}/warehouses/{warehouse}', [ClientController::class, 'assignWarehouse'])->name('clients.warehouses.assign');
        Route::put('/clients/{client}/services/{serviceCode}', [ClientController::class, 'assignService'])->name('clients.services.update');

        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('/warehouses/{warehouse}/service-areas', [WarehouseController::class, 'serviceAreas'])->name('warehouses.service-areas');
    });
