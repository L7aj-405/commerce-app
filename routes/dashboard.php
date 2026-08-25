<?php

declare(strict_types=1);

use App\Http\Controllers\Dashboard\BonDeLivraisonController;
use App\Http\Controllers\Dashboard\ConnectionProfileController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DeliveryConnectionController;
use App\Http\Controllers\Dashboard\DeliveryController;
use App\Http\Controllers\Dashboard\DeliveryNoteController;
use App\Http\Controllers\Dashboard\DeliveryShipmentController;
use App\Http\Controllers\Dashboard\DepartmentController;
use App\Http\Controllers\Dashboard\FacturesController;
use App\Http\Controllers\Dashboard\IntegrationsController;
use App\Http\Controllers\Dashboard\InvoiceController;
use App\Http\Controllers\Dashboard\OperationsController;
use App\Http\Controllers\Dashboard\OrderController;
use App\Http\Controllers\Dashboard\OrderNotificationController;
use App\Http\Controllers\Dashboard\ProductCleanupController;
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

            // Lightweight polling for order badges/toasts — no dedicated
            // permission gate; every result is already scoped to the acting
            // user's own notifications and their own store-permission checks
            // (see OrderNotificationController).
            Route::prefix('notifications')->name('notifications.')->group(function () {
                Route::get('/order-counts', [OrderNotificationController::class, 'counts'])->name('order-counts');
                Route::post('/mark-seen',   [OrderNotificationController::class, 'markSeen'])->name('mark-seen');
            });

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

                // Waiting Stock Reallocation actions — scoped via
                // OperationsQueueService::findWaitingOrder(), same
                // warehouse-operator + permission boundary as the queue itself.
                Route::post('/waiting-stock/{type}/{id}/recheck',           [OperationsController::class, 'recheckWaitingStock'])->name('waiting-stock.recheck');
                Route::post('/waiting-stock/{type}/{id}/request-transfer',  [OperationsController::class, 'requestWaitingStockTransfer'])->name('waiting-stock.request-transfer');
                Route::post('/waiting-stock/{type}/{id}/restock-requested',[OperationsController::class, 'markWaitingStockRestockRequested'])->name('waiting-stock.restock-requested');
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
                Route::get('/sync-batches/{batch}', [ProductSyncController::class, 'getSyncBatchStatus'])->name('sync.batches.show');

                Route::middleware('perm:products.manage')->group(function () {
                    Route::get('/create',       [ProductController::class, 'create'])->name('create');
                    Route::post('/',            [ProductController::class, 'store'])->name('store');
                    Route::patch('/{product}',  [ProductController::class, 'update'])->name('update');
                    Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
                    // Explicit-target publish (SaaS -> platform). Replaces the old
                    // /push route, which auto-published to every active connection
                    // for the store regardless of platform.
                    Route::post('/{product}/publish', [ProductController::class, 'publish'])->name('publish');
                    // Queued publish (CV5) — returns immediately with a
                    // batch id instead of waiting for the platform HTTP calls.
                    Route::post('/{product}/publish-queued', [ProductController::class, 'publishQueued'])->name('publish-queued');
                    Route::get('/publish-batches/{batch}',   [ProductController::class, 'publishBatchStatus'])->name('publish-batches.show');
                    Route::post('/bulk-publish',       [ProductController::class, 'bulkPublish'])->name('bulk-publish');
                    Route::post('/sync/start',  [ProductSyncController::class, 'startSync'])->name('sync.start');

                    // Safe bulk cleanup / resync-reset for imported products.
                    Route::prefix('bulk')->name('bulk.')->group(function () {
                        Route::post('/archive',        [ProductCleanupController::class, 'archive'])->name('archive');
                        Route::post('/unlink-channel', [ProductCleanupController::class, 'unlinkChannel'])->name('unlink-channel');
                        Route::post('/reset-sync',     [ProductCleanupController::class, 'resetSync'])->name('reset-sync');
                        Route::post('/reset-sync-all', [ProductCleanupController::class, 'resetSyncAll'])->name('reset-sync-all');
                        Route::post('/purge-preview',  [ProductCleanupController::class, 'purgePreview'])->name('purge-preview');
                        Route::post('/purge',          [ProductCleanupController::class, 'purge'])->name('purge');
                    });
                });

                // Inventory-safe stock adjustments — gated by the same
                // permission the Stock dashboard's quick-adjust already uses.
                Route::middleware('perm:stock.adjust')->group(function () {
                    Route::post('/{product}/stock', [ProductController::class, 'adjustStock'])->name('stock');
                });
            });

            Route::middleware('perm:stock.view')->prefix('stock')->name('stock.')->group(function () {
                Route::get('/',                  [StockController::class, 'index'])->name('index');
                Route::get('/movements',         [StockController::class, 'movements'])->name('movements');
                Route::post('/{product}/adjust', [StockController::class, 'adjustStock'])
                    ->middleware('perm:stock.adjust')->name('adjust');
                // Read-only — never writes anything, so it only needs stock.view.
                Route::post('/{product}/preview-adjustment', [StockController::class, 'previewAdjustment'])->name('preview-adjustment');

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
                // Real-API-truth Shopify capability diagnostics — distinct from
                // the generic testConnection() above, which only reports a
                // pass/fail gated on the token's self-reported scope string.
                Route::post('/shopify/diagnostics', [IntegrationsController::class, 'shopifyDiagnostics'])->name('shopify.diagnostics');

                // Connection Profile — auth/sync status, sync actions, reset
                // actions (never touch credentials), and the separate,
                // dangerous disconnect action (never confused with reset).
                Route::prefix('connections/{connection}')->name('connections.')->group(function () {
                    Route::get('/',                            [ConnectionProfileController::class, 'show'])->name('show');
                    Route::post('/test',                       [ConnectionProfileController::class, 'test'])->name('test');
                    Route::post('/sync-products',               [ConnectionProfileController::class, 'syncProducts'])->name('sync-products');
                    Route::post('/sync-orders',                 [ConnectionProfileController::class, 'syncOrders'])->name('sync-orders');
                    Route::post('/sync-products/queue',         [ConnectionProfileController::class, 'queueProductSync'])->name('sync-products.queue');
                    Route::post('/sync-orders/queue',           [ConnectionProfileController::class, 'queueOrderSync'])->name('sync-orders.queue');
                    Route::get('/sync-orders/batches/{batch}',  [ConnectionProfileController::class, 'getOrderSyncBatchStatus'])->name('sync-orders.batch-status');
                    Route::post('/reset-product-mappings',      [ConnectionProfileController::class, 'resetProductMappings'])->name('reset-product-mappings');
                    Route::post('/reset-product-cursor',        [ConnectionProfileController::class, 'resetProductCursor'])->name('reset-product-cursor');
                    Route::post('/reset-order-cursor',          [ConnectionProfileController::class, 'resetOrderCursor'])->name('reset-order-cursor');
                    Route::post('/archive-imported-products',   [ConnectionProfileController::class, 'archiveImportedProducts'])->name('archive-imported-products');
                    Route::post('/disconnect',                  [ConnectionProfileController::class, 'disconnect'])->name('disconnect');
                });
            });

            // External delivery providers (Ozon Express first). Separate from
            // the internal /my-deliveries driver queue above and from the
            // Dispatch board's own order_shipments bookkeeping — this is the
            // provider-specific integration layer that feeds into it.
            Route::middleware('perm:delivery.connections.manage')->prefix('delivery-connections')->name('delivery-connections.')->group(function () {
                Route::get('/',                    [DeliveryConnectionController::class, 'index'])->name('index');
                Route::post('/ozon',                [DeliveryConnectionController::class, 'storeOzon'])->name('ozon.store');
                Route::post('/{connection}/test',        [DeliveryConnectionController::class, 'test'])->name('test');
                Route::post('/{connection}/sync-cities',  [DeliveryConnectionController::class, 'syncCities'])->name('sync-cities');
                Route::post('/{connection}/cities/map',   [DeliveryConnectionController::class, 'mapCity'])->name('cities.map');
                Route::post('/{connection}/cities/map-all-suggested', [DeliveryConnectionController::class, 'mapAllSuggested'])->name('cities.map-all-suggested');
                Route::post('/{connection}/cities/clear-mapping',     [DeliveryConnectionController::class, 'clearMapping'])->name('cities.clear-mapping');
                Route::post('/{connection}/disconnect',   [DeliveryConnectionController::class, 'disconnect'])->name('disconnect');
            });

            Route::prefix('delivery-shipments')->name('delivery-shipments.')->group(function () {
                Route::middleware('perm:delivery.shipments.create')
                    ->post('/orders/{order}/ozon', [DeliveryShipmentController::class, 'sendToOzon'])->name('send-ozon');
                Route::middleware('perm:delivery.shipments.create')
                    ->post('/{shipment}/retry-verification', [DeliveryShipmentController::class, 'retryVerification'])->name('retry-verification');
                Route::middleware('perm:delivery.shipments.track')
                    ->post('/{shipment}/refresh-tracking', [DeliveryShipmentController::class, 'refreshTracking'])->name('refresh-tracking');
            });

            Route::middleware('perm:delivery.notes.manage')->prefix('delivery-notes')->name('delivery-notes.')->group(function () {
                Route::post('/ozon',                         [DeliveryNoteController::class, 'create'])->name('create');
                Route::post('/{deliveryNote}/add-shipments',  [DeliveryNoteController::class, 'addShipments'])->name('add-shipments');
                Route::post('/{deliveryNote}/save',           [DeliveryNoteController::class, 'save'])->name('save');
            });
        });
    });
