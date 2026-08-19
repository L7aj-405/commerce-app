<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\BonDeLivraisonController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DeliveryController;
use App\Http\Controllers\Dashboard\DepartmentController;
use App\Http\Controllers\Dashboard\FacturesController;
use App\Http\Controllers\Dashboard\IntegrationsController;
use App\Http\Controllers\Dashboard\InvoiceController;
use App\Http\Controllers\Dashboard\OperationsController;
use App\Http\Controllers\Dashboard\OrderController;
use App\Http\Controllers\Dashboard\ProductController;
use App\Http\Controllers\Dashboard\ProductSyncController;
use App\Http\Controllers\Dashboard\ReturnController;
use App\Http\Controllers\Dashboard\RoleController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\StockController;
use App\Http\Controllers\Dashboard\StockTransferController;
use App\Http\Controllers\Dashboard\StoreController;
use App\Http\Controllers\Dashboard\StoreSwitchController;
use App\Http\Controllers\Dashboard\TeamController;
use App\Http\Controllers\Dashboard\WarehouseController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', ResolveTenant::class, 'onboarding_complete', 'can_dashboard', 'confine_driver'])
    ->prefix('dashboard')
    ->group(function () {

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::name('dashboard.')->group(function () {

            Route::post('/stores/switch', [StoreSwitchController::class, 'switch'])->name('stores.switch');

            Route::middleware('perm:stores.manage')->prefix('stores')->name('stores.')->group(function () {
                Route::get('/',                [StoreController::class, 'index'])->name('index');
                Route::get('/create',          [StoreController::class, 'create'])->name('create');
                Route::post('/',               [StoreController::class, 'store'])->name('store');
                Route::get('/{store}/edit',    [StoreController::class, 'edit'])->name('edit');
                Route::patch('/{store}',       [StoreController::class, 'update'])->name('update');
                Route::delete('/{store}',      [StoreController::class, 'destroy'])->name('destroy');
            });

            // Internal delivery agent's own mobile queue. Its own permission
            // (orders.deliver) so a driver needs neither orders.view nor the
            // department boards; every action is scoped to their own shipments.
            Route::middleware('perm:orders.deliver')->prefix('my-deliveries')->name('deliveries.')->group(function () {
                Route::get('/', [DeliveryController::class, 'index'])->name('index');
                Route::post('/{shipmentId}/delivered', [DeliveryController::class, 'delivered'])->name('delivered');
                Route::post('/{shipmentId}/failed',    [DeliveryController::class, 'failed'])->name('failed');
            });

            // Dedicated department work queues. The unified board at
            // /dashboard/orders/manage stays the cross-department overview.
            Route::middleware('perm:orders.view')->prefix('departments')->name('departments.')->group(function () {
                Route::get('/confirmation', [DepartmentController::class, 'confirmation'])->name('confirmation');
                Route::get('/packing',      [DepartmentController::class, 'packing'])->name('packing');
                Route::get('/dispatch',     [DepartmentController::class, 'dispatch'])->name('dispatch');

                // Assignment is self-service: the controller checks the caller
                // holds the permission for the phase the order sits in.
                Route::post('/take-next/{phase}',        [DepartmentController::class, 'takeNext'])->name('take-next');
                Route::post('/{type}/{id}/claim',        [DepartmentController::class, 'claim'])->name('claim');
                Route::post('/{type}/{id}/release',      [DepartmentController::class, 'release'])->name('release');

                // Authorized in the controller rather than by `perm:` middleware,
                // so the coarse `orders.manage` also passes.
                Route::post('/{type}/{id}/carrier',               [DepartmentController::class, 'assignCarrier'])->name('carrier');
                Route::post('/shipments/{shipmentId}/delivered',  [DepartmentController::class, 'markDelivered'])->name('delivered');
                Route::post('/shipments/{shipmentId}/failed',     [DepartmentController::class, 'markFailed'])->name('failed');

                // Carrier handover sheet (A4 PDF, streamed inline for printing).
                Route::get('/manifests/{reference}', [DepartmentController::class, 'manifest'])
                    ->where('reference', '[A-Za-z0-9\-]+')->name('manifest');
            });

            // Single-station operational queues, scoped by warehouse operator
            // rather than the active store (OperationsQueueService). Claim,
            // release and take-next are handled by the existing departments
            // routes above — a warehouse claim is the same action regardless
            // of which queue page linked to it.
            Route::middleware('perm:orders.fulfil')->prefix('operations')->name('operations.')->group(function () {
                Route::get('/waiting-stock',  [OperationsController::class, 'waitingStock'])->name('waiting-stock');
                Route::get('/picking',        [OperationsController::class, 'picking'])->name('picking');
                Route::get('/packing',        [OperationsController::class, 'packing'])->name('packing');
                Route::get('/ready-delivery', [OperationsController::class, 'readyForDelivery'])->name('ready-delivery');
            });

            Route::middleware('perm:inventory.transfers.receive')->prefix('operations/transfers')->name('operations.transfers.')->group(function () {
                Route::get('/',                    [OperationsController::class, 'transferReceiving'])->name('index');
                Route::post('/{transfer}/receive', [OperationsController::class, 'receiveTransfer'])->name('receive');
            });

            Route::middleware('perm:orders.view')->prefix('orders')->name('orders.')->group(function () {
                Route::get('/',                    [OrderController::class, 'index'])->name('index');
                Route::get('/manage',              [OrderController::class, 'manage'])->name('manage');
                Route::post('/{type}/{id}/status', [OrderController::class, 'updateStatus'])->name('status');

                // Inspection department. Declared before /{order} so "returns"
                // is not swallowed by the order show route.
                Route::prefix('returns')->name('returns.')->group(function () {
                    Route::get('/', [ReturnController::class, 'index'])->name('index');

                    Route::middleware('perm:orders.inspect')->group(function () {
                        Route::get('/{id}',             [ReturnController::class, 'show'])->name('show');
                        Route::post('/{id}/disposition', [ReturnController::class, 'disposition'])->name('disposition');
                        Route::post('/{id}/close',       [ReturnController::class, 'close'])->name('close');
                    });
                });

                // Online orders live in their own table and bind by ULID, so
                // they get dedicated routes. Declared before the POS-bound
                // /{order} catch-all so "online" isn't resolved as a receipt #.
                Route::get('/online/{order}/receipt', [OrderController::class, 'receiptOnline'])->name('online.receipt');
                Route::get('/online/{order}',         [OrderController::class, 'showOnline'])->name('online.show');

                Route::get('/{order}/receipt',     [OrderController::class, 'receipt'])->name('receipt');
                Route::get('/{order}',             [OrderController::class, 'show'])->name('show');
            });

            Route::middleware('perm:products.view')->prefix('products')->name('products.')->group(function () {
                Route::get('/',                  [ProductController::class, 'index'])->name('index');
                Route::get('/{product}/edit',    [ProductController::class, 'edit'])->name('edit');

                Route::get('/sync/connections', [ProductSyncController::class, 'getConnections'])->name('sync.connections');
                Route::get('/sync/progress',     [ProductSyncController::class, 'getSyncProgress'])->name('sync.progress');

                Route::middleware('perm:products.manage')->group(function () {
                    Route::get('/create',       [ProductController::class, 'create'])->name('create');
                    Route::post('/',            [ProductController::class, 'store'])->name('store');
                    Route::patch('/{product}',  [ProductController::class, 'update'])->name('update');
                    Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
                    Route::post('/sync/start',  [ProductSyncController::class, 'startSync'])->name('sync.start');
                });
            });

            Route::middleware('perm:stock.view')->prefix('stock')->name('stock.')->group(function () {
                Route::get('/',                  [StockController::class, 'index'])->name('index');
                Route::get('/movements',         [StockController::class, 'movements'])->name('movements');
                Route::post('/{product}/adjust', [StockController::class, 'adjustStock'])
                    ->middleware('perm:stock.adjust')->name('adjust');

                // Stock Transfer & Outbound Movement (Bon de Sortie). Creating a
                // transfer mutates stock, so it needs stock.adjust; listing and
                // printing the slip only need stock.view.
                Route::prefix('transfers')->name('transfers.')->group(function () {
                    Route::get('/',                [StockTransferController::class, 'index'])->name('index');
                    Route::get('/{transfer}/slip', [StockTransferController::class, 'slip'])->name('slip');

                    Route::middleware('perm:stock.adjust')->group(function () {
                        Route::get('/create', [StockTransferController::class, 'create'])->name('create');
                        Route::post('/',      [StockTransferController::class, 'store'])->name('store');
                    });
                });
            });

            Route::middleware('perm:warehouses.manage')->prefix('warehouses')->name('warehouses.')->group(function () {
                Route::get('/',                   [WarehouseController::class, 'index'])->name('index');
                Route::get('/create',             [WarehouseController::class, 'create'])->name('create');
                Route::post('/',                  [WarehouseController::class, 'store'])->name('store');
                Route::get('/{warehouse}/edit',   [WarehouseController::class, 'edit'])->name('edit');
                Route::patch('/{warehouse}',      [WarehouseController::class, 'update'])->name('update');
            });

            Route::middleware('perm:factures.view')->prefix('factures')->name('factures.')->group(function () {
                Route::get('/',                     [FacturesController::class, 'index'])->name('index');
                Route::get('/{facture}',            [FacturesController::class, 'show'])->name('show');
                Route::get('/{facture}/download',   [FacturesController::class, 'download'])->name('download');
            });

            // Invoice lifecycle (order -> invoice). Per-action authorization is
            // enforced inside the controller via FacturePolicy + Gate abilities.
            Route::prefix('invoices')->name('invoices.')->group(function () {
                Route::post('/',                   [InvoiceController::class, 'store'])->name('store');
                Route::get('/{facture}/download',  [InvoiceController::class, 'download'])->name('download');
                Route::get('/{facture}/receipt',   [InvoiceController::class, 'receipt'])->name('receipt');
                Route::post('/{facture}/email',    [InvoiceController::class, 'email'])->name('email');
                Route::patch('/{facture}',         [InvoiceController::class, 'amend'])->name('amend');
                Route::post('/{facture}/void',     [InvoiceController::class, 'void'])->name('void');
                Route::post('/{facture}/pay',      [InvoiceController::class, 'pay'])->name('pay');
            });

            Route::middleware('perm:factures.view')->prefix('bon-de-livraison')->name('bon.')->group(function () {
                Route::get('/',              [BonDeLivraisonController::class, 'index'])->name('index');
                Route::patch('/{bon}/status', [BonDeLivraisonController::class, 'updateStatus'])
                    ->middleware('perm:bon.manage')->name('status');
            });

            Route::middleware('perm:team.manage')->prefix('team')->name('team.')->group(function () {
                Route::get('/',                              [TeamController::class, 'index'])->name('index');
                Route::get('/add',                           [TeamController::class, 'create'])->name('add');
                Route::post('/add',                          [TeamController::class, 'storeMember'])->name('store');
                Route::get('/invite',                        [TeamController::class, 'invite'])->name('invite');
                Route::post('/invite',                       [TeamController::class, 'sendInvitation'])->name('send');
                Route::get('/members/{member}/edit',         [TeamController::class, 'editMember'])->name('members.edit');
                Route::patch('/members/{member}',            [TeamController::class, 'updateMember'])->name('members.update');
                Route::delete('/members/{member}',           [TeamController::class, 'removeMember'])->name('remove');
                Route::delete('/invitations/{invitation}',   [TeamController::class, 'revokeInvitation'])->name('revoke');
            });

            Route::middleware('perm:roles.manage')->prefix('roles')->name('roles.')->group(function () {
                Route::get('/',              [RoleController::class, 'index'])->name('index');
                Route::get('/create',        [RoleController::class, 'create'])->name('create');
                Route::post('/',             [RoleController::class, 'store'])->name('store');
                Route::get('/{role}/edit',   [RoleController::class, 'edit'])->name('edit');
                Route::patch('/{role}',      [RoleController::class, 'update'])->name('update');
                Route::delete('/{role}',     [RoleController::class, 'destroy'])->name('destroy');
            });

            Route::middleware('perm:settings.manage')->prefix('settings')->name('settings.')->group(function () {
                Route::get('/',  [SettingsController::class, 'index'])->name('index');
                Route::post('/', [SettingsController::class, 'update'])->name('update');
            });

            Route::middleware('perm:integrations.manage')->prefix('integrations')->name('integrations.')->group(function () {
                Route::get('/',             [IntegrationsController::class, 'index'])->name('index');
                Route::get('/woocommerce',  [IntegrationsController::class, 'woocommerce'])->name('woocommerce');
                Route::post('/woocommerce', [IntegrationsController::class, 'saveWoocommerce'])->name('woocommerce.save');
                Route::get('/shopify',      [IntegrationsController::class, 'shopify'])->name('shopify');
                Route::post('/shopify',     [IntegrationsController::class, 'saveShopify'])->name('shopify.save');
                Route::get('/youcan',       [IntegrationsController::class, 'youcan'])->name('youcan');
                Route::post('/youcan',      [IntegrationsController::class, 'saveYoucan'])->name('youcan.save');
                Route::get('/whatsapp',     [IntegrationsController::class, 'whatsapp'])->name('whatsapp');
                Route::post('/whatsapp',    [IntegrationsController::class, 'saveWhatsapp'])->name('whatsapp.save');
                Route::post('/test/{platform}', [IntegrationsController::class, 'testConnection'])->name('test');
            });
        });
    });
