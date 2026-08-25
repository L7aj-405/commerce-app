# anatomy.md

> Auto-maintained by OpenWolf. Last scanned: 2026-08-25T01:19:54.030Z
> Files: 388 tracked | Anatomy hits: 0 | Misses: 0

## ../../../../../../laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/


## ../../../../.claude/jobs/a866b444/tmp/


## ../../../../.claude/plans/

- `parallel-prancing-flame.md` — Delivery Provider Foundation + Ozon Express Integration (~4721 tok)
- `tidy-frolicking-moler.md` — Phase CV1 — Canonical Product Options and Variant Wizard Persistence (~3384 tok)

## ../../../../.claude/projects/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/memory/

- `feedback_implementation_first_no_test_runs.md` (~449 tok)
- `MEMORY.md` — Memory Index (~46 tok)

## ../../../../AppData/Local/Temp/


## ../../../../AppData/Local/Temp/claude/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/358ee1f2-0cee-456b-910c-65b6637b1237/scratchpad/

- `ReproBugTest.php` (~886 tok)

## ../../../../AppData/Local/Temp/claude/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/8ec56ea9-1d30-4eb0-9da3-38a9027103e0/scratchpad/


## ../../../../AppData/Local/Temp/claude/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/ebce23f8-360d-4515-aec0-3074dd0ec06b/scratchpad/


## ./


## .claude/


## .claude/rules/


## .github/workflows/


## app/


## app/Actions/Fortify/


## app/Concerns/

- `ProfileValidationRules.php` — Get the validation rules used to validate user profiles. (~342 tok)

## app/Connectors/

- `ShopifyConnector.php` — Shopify Admin REST API connector (version 2024-01). (~12456 tok)
- `WooCommerceConnector.php` — Parse WooCommerce product to normalized format (~9504 tok)

## app/Connectors/Delivery/

- `OzonExpressConnector.php` — Ozon Express Morocco (https://api.ozonexpress.ma). Auth is embedded in the (~10523 tok)
- `OzonStatusMapper.php` — Maps Ozon's raw tracking status strings to the shared normalized set. (~608 tok)

## app/Console/Commands/

- `CatalogCleanupPreviewCommand.php` — CatalogCleanupPreviewCommand: handle (~551 tok)
- `DiagnoseProductCommand.php` — DiagnoseProductCommand: handle (~358 tok)
- `PurgeImportedProductsCommand.php` — PurgeImportedProductsCommand: handle (~816 tok)
- `PurgeProductCommand.php` — PurgeProductCommand: handle (~595 tok)
- `RepairProductCommand.php` — RepairProductCommand: handle (~551 tok)

## app/Contracts/

- `DeliveryProviderConnectorInterface.php` — Contract every delivery provider connector implements (Ozon Express first). (~729 tok)

## app/Enums/


## app/Events/


## app/Exceptions/


## app/Factories/


## app/Http/Controllers/


## app/Http/Controllers/Admin/


## app/Http/Controllers/Api/

- `ShopifyWebhookController.php` — Single endpoint for every Shopify webhook topic — the event is read (~1745 tok)
- `WooCommerceWebhookController.php` — Single endpoint for every WooCommerce order webhook topic — mirrors (~1653 tok)

## app/Http/Controllers/Auth/


## app/Http/Controllers/Dashboard/

- `ConnectionProfileController.php` — A per-connection "control panel" — the single place to check auth status, (~8641 tok)
- `DashboardController.php` — index (~1906 tok)
- `DeliveryConnectionController.php` — Delivery provider connection settings — Ozon Express first. (~2962 tok)
- `DeliveryNoteController.php` — create, addShipments, save (~707 tok)
- `DeliveryShipmentController.php` — Sending a packed order to an external delivery provider, and refreshing its tracking. (~1341 tok)
- `DepartmentController.php` — Focused work queues, one per operational department. (~5016 tok)
- `IntegrationsController.php` — Topics currently wired up end to end (Shopify Integration Workflow Upgrade). (~4235 tok)
- `OperationsController.php` — Focused, single-station queues layered over the existing department (~1642 tok)
- `OrderController.php` — Unified orders list — POS and online in one filterable, paginated table. (~5289 tok)
- `OrderNotificationController.php` — Lightweight polling endpoint for order badges/toasts — no websockets/ (~1054 tok)
- `ProductCleanupController.php` — Safe bulk cleanup for imported products — archive, unlink a platform (~1768 tok)
- `ProductController.php` — index, syncFromPlatform, create, store (~10438 tok)
- `ProductSyncController.php` — كيجيب الـ Connections المتاحة بحال لي كان ف دالة render() (~1324 tok)
- `StockController.php` — id => name for the active store's sellable warehouses (set per request). (~9786 tok)
- `StockTransferController.php` — index, create, store, slip (~2839 tok)
- `StoreController.php` — Add Store is Organization-first: it never invents a workspace. It shows (~2348 tok)
- `WarehouseController.php` — index, create, store, edit, update (~1879 tok)

## app/Http/Controllers/Onboarding/

- `AgencyOnboardingController.php` — show, storeOrganization, storeServices, storeWarehouses, storeClient + 4 more (~2385 tok)
- `MerchantOnboardingController.php` — show, storeOrganization, storeStore, storeWarehouses, storeSetup + 1 more (~1564 tok)
- `OnboardingController.php` — The literal first onboarding question: "How will you use the (~2042 tok)

## app/Http/Controllers/Pos/

- `CheckoutController.php` — store (~1235 tok)
- `PosController.php` — Eager loads needed to present a product with its variants in one query set: (~1924 tok)

## app/Http/Controllers/Settings/

- `SettingsController.php` — Account-level settings (Profile/Appearance/Security) — replaces the (~1116 tok)

## app/Http/Middleware/

- `HandleInertiaRequests.php` — HandleInertiaRequests: version, share (~1328 tok)

## app/Http/Requests/Auth/


## app/Jobs/

- `ExternalStockPushJob.php` — Phase S6 — pushes a product's (or one variant's) already-committed local (~1422 tok)
- `OrderSyncJob.php` — Imports one PlatformConnection's orders in the background and records the (~1392 tok)
- `ProductPublishJob.php` — Publishes one canonical Product to one PlatformConnection and records the (~1147 tok)
- `ProductSyncJob.php` — Imports one PlatformConnection's catalog into the store and records the (~960 tok)
- `RecheckWaitingStockOrdersJob.php` — Dispatched by InventoryEngine whenever available stock increases at a (~474 tok)
- `TrackActiveShipmentsJob.php` — Scheduled poll of every non-terminal Ozon shipment, grouped by connection for bulk tracking. (~466 tok)

## app/Jobs/Pos/


## app/Listeners/

- `CreateNewOrderNotifications.php` — Fires once per genuinely NEW order (OrderCreated is only dispatched from (~744 tok)

## app/Livewire/


## app/Livewire/Actions/


## app/Livewire/Forms/


## app/Livewire/Orders/


## app/Livewire/Products/


## app/Livewire/Profile/


## app/Livewire/Stores/


## app/Livewire/Stores/Connections/


## app/Livewire/Stores/Settings/


## app/Mail/


## app/Models/

- `CityDeliveryProviderMapping.php` — Links an internal City to one provider's city (e.g. Ozon). (~198 tok)
- `DeliveryConnection.php` — A store's credentials + settings for one delivery provider (e.g. Ozon (~951 tok)
- `DeliveryNote.php` — A carrier handover batch (provider-side BL), distinct from the internal MAN- manifest system. (~317 tok)
- `DeliveryNoteShipment.php` — Model — 2 fields (~74 tok)
- `DeliveryProvider.php` — Model — 3 fields (~116 tok)
- `DeliveryProviderCity.php` — One provider's city, as synced from its API (e.g. Ozon's /cities). (~257 tok)
- `InventoryReservation.php` — Model — 13 fields, 4 rels (~383 tok)
- `Order.php` — Model — 47 fields, 4 rels (~2535 tok)
- `OrderNotification.php` — Per-user "new order" notification row — one per (user, order, type), see the migration's own doc com (~364 tok)
- `OrderSyncBatch.php` — Mirrors ProductSyncBatch — one row per queued "Sync orders now"/"Full order resync" action, summed f (~735 tok)
- `OrderSyncResult.php` — One row per (order sync batch, platform connection) — mirrors ProductSyncResult. (~390 tok)
- `PlatformConnection.php` — Model — 20 fields, 5 rels (~982 tok)
- `PosOrderItem.php` — Model — 13 fields, 3 rels (~369 tok)
- `Product.php` — Platform-specific identities for this canonical catalog product. (~1797 tok)
- `ProductAttribute.php` — Model — 4 fields, 2 rels (~315 tok)
- `ProductAttributeValue.php` — Values the wizard should treat as real options — never a user-archived one. (~441 tok)
- `ProductPublishBatch.php` — Recompute counts/status from the batch's own result rows. (~627 tok)
- `ProductPublishResult.php` — Model — 10 fields, 4 rels (~383 tok)
- `ProductSyncBatch.php` — Recompute counts/status from the batch's own result rows. (~625 tok)
- `ProductSyncResult.php` — One row per (sync batch, platform connection) — a sync operates on a whole connection's catalog, not (~335 tok)
- `Shipment.php` — The rich, provider-specific shipment record (Ozon first). Separate from (~1345 tok)
- `ShipmentEvent.php` — Append-only tracking history for one shipment. (~236 tok)
- `StockLedger.php` — Model — table: stock_ledger, 12 fields, 5 rels (~368 tok)
- `StockTransfer.php` — A Stock Transfer / Bon de Sortie (exit slip): the authoritative record of goods (~816 tok)
- `StockTransferItem.php` — Model — 8 fields, 3 rels (~270 tok)
- `Store.php` — Model — 18 fields, 16 rels (~3193 tok)

## app/Models/Concerns/


## app/Models/Scopes/


## app/Notifications/


## app/Policies/


## app/Providers/

- `AppServiceProvider.php` — Register any application services. (~1322 tok)
- `FortifyServiceProvider.php` — Register any application services. (~839 tok)

## app/Repositories/


## app/Services/


## app/Services/Agency/

- `AgencyWorkspaceService.php` — AgencyWorkspaceService: createClient, createAgencyWarehouse, assignWarehouse, assignService (~1276 tok)

## app/Services/Catalog/

- `ProductCleanupSafetyService.php` — Read-only safety check for the product cleanup/resync-reset actions. (~3839 tok)
- `ProductCleanupService.php` — Mutating half of the imported-product cleanup toolkit. Every method takes (~2515 tok)
- `ProductDiagnosticService.php` — Phase S7 — read-only diagnostics + conservative repair for catalog data (~2100 tok)
- `ProductStockSnapshotService.php` — Single source of truth for "what stock should the UI show" — always (~2431 tok)
- `ProductVariantWizardService.php` — Turns a product-edit-wizard submission (option definitions + variant (~5300 tok)

## app/Services/Delivery/

- `DeliveryCityMappingResolver.php` — Resolves which Ozon (or any provider) city a packed order should ship to, (~2769 tok)
- `DeliveryCityMappingSuggestionService.php` — Conservative internal-city -> provider-city matching, for the "Map all (~1763 tok)
- `DeliveryNoteService.php` — Orchestrates Ozon's Bon de Livraison (delivery note) flow: create, add parcels, save, get PDFs. (~890 tok)
- `OzonCityMappingService.php` — OzonCityMappingService: syncCities, mapCity, mapAllSuggested, unmappedCities (~1160 tok)
- `OzonShipmentCreationException.php` — Thrown when Ozon rejects add-parcel or its response can't be parsed for a (~198 tok)
- `OzonShipmentService.php` — Sends a packed order to Ozon Express and records the result. (~4689 tok)
- `ShipmentTrackingService.php` — Refreshes Ozon tracking state and, when a shipment reaches a terminal (~1320 tok)

## app/Services/Inventory/

- `AllocationCompletionService.php` — The single place that decides "this allocation's shortages are all (~558 tok)
- `CatalogInventoryService.php` — CatalogInventoryService: forCatalog, resolve (~1580 tok)
- `InventoryEngine.php` — InventoryEngine: setOnHand, adjustOnHand, reserve, release + 6 more (~3809 tok)
- `InventoryTransferService.php` — InventoryTransferService: request, approve, cancel, ship + 1 more (~1916 tok)
- `OrderLineInventoryResolution.php` — The outcome of resolving one online order line to a local catalog (~441 tok)
- `OrderLineInventoryResolver.php` — The single, platform-agnostic resolver for "what local catalog record — (~4312 tok)
- `WaitingStockReallocationService.php` — Waiting Stock Reallocation — recovers orders stuck in WaitingForStock once (~1427 tok)
- `WarehouseAllocationService.php` — WarehouseAllocationService: allocate, release, consume, restoreConsumed + 1 more (~5810 tok)

## app/Services/Invoicing/


## app/Services/Meta/


## app/Services/Onboarding/

- `AgencyOnboardingService.php` — Backs the agency onboarding wizard (Step 8 / A1-A10). Reuses (~1765 tok)
- `MerchantOnboardingService.php` — Backs the merchant onboarding wizard (Step 8 / M1-M6). Each method is one (~1964 tok)

## app/Services/Orders/

- `OperationsQueueService.php` — Cross-store operational queues, scoped by warehouse OPERATOR rather than the (~8097 tok)
- `OrderWorkflowService.php` — Every fulfillment status change goes through here — the board, the WhatsApp (~3103 tok)
- `ReturnInspectionService.php` — Reverse logistics: receiving returned goods and routing each line to active or (~3691 tok)
- `StockMovementWriter.php` — The single place stock quantities are mutated by the order lifecycle. (~1156 tok)

## app/Services/Pos/

- `DocumentGenerationService.php` — Render a finalized Facture to an A4 PDF and persist it. Returns the (~2978 tok)
- `OrderProcessingService.php` — Create a POS order with its line items. Runs in a single transaction so (~3623 tok)

## app/Services/Publishing/

- `ProductChannelPublisher.php` — Publishes one canonical Product to one PlatformConnection using the (~5437 tok)
- `ProductOptionSnapshot.php` — Reads a Product's canonical ProductAttribute/ProductAttributeValue/variant (~908 tok)
- `ProductPublishReadinessService.php` — Read-only readiness checks — never mutates anything, never calls a (~1647 tok)

## app/Services/Publishing/Shopify/

- `ShopifyProductPayloadMapper.php` — Converts a canonical SaaS Product into a Shopify Admin REST product (~1603 tok)

## app/Services/Publishing/WooCommerce/

- `WooCommerceProductPayloadMapper.php` — Converts a canonical SaaS Product into WooCommerce REST payloads — a (~965 tok)

## app/Services/Shopify/

- `ShopifyAuthException.php` — Declares ShopifyAuthException (~39 tok)
- `ShopifyAuthService.php` — Generates and caches short-lived Shopify Admin API tokens via the (~2714 tok)
- `ShopifyCapabilityDiagnosticsService.php` — Real-API-truth diagnostics for a Shopify admin_client_credentials (~2599 tok)
- `ShopifyOrderMapper.php` — Map a Shopify orders/create|updated webhook payload onto the canonical (~271 tok)
- `ShopifyProductMapper.php` — Map a Shopify products/create|update webhook payload onto the canonical (~241 tok)
- `ShopifyWebhookVerifier.php` — Verify a Shopify webhook's X-Shopify-Hmac-Sha256 header against the raw (~203 tok)

## app/Services/Stocks/

- `StockTransferService.php` — Record a stock transfer / Bon de Sortie and move the goods atomically. (~2656 tok)

## app/Services/Sync/

- `OrderSyncService.php` — Page through orders on the platform and persist them to the store. (~3164 tok)
- `ProductPublishService.php` — Orchestrates explicit-target product publishing (SaaS -> platform). (~2171 tok)
- `ProductPushService.php` — Create a local product on every selected channel that does not already (~9400 tok)
- `ProductSyncService.php` — Pull one remote product into the canonical Store catalog. (~7728 tok)

## app/Services/WhatsApp/


## app/Services/WooCommerce/

- `WooCommerceOrderMapper.php` — Map a WooCommerce order.created|updated webhook payload onto the (~285 tok)
- `WooCommerceWebhookVerifier.php` — Verify a WooCommerce webhook's X-WC-Webhook-Signature header against (~253 tok)

## app/Support/

- `OnboardingOptions.php` — Static option lists shared by every onboarding controller/page — kept in (~1309 tok)
- `OrderAddressSummary.php` — The customer's ORIGINAL shipping/delivery address, as the platform sent (~1958 tok)
- `OrderLineItems.php` — One line-item shape for both order models, for code that has to touch stock. (~1754 tok)
- `OrderPresenter.php` — Normalizes POS and online orders into one shape for the Order Management view, (~3496 tok)
- `OrderSourceSummary.php` — Phase OST — single source of truth for "where did this order come from", (~1428 tok)
- `PermissionCatalog.php` — Central catalogue of every granular permission a store role can grant. (~3544 tok)
- `WaitingStockState.php` — Single source of truth for "what should a waiting-stock order's badge say" (~979 tok)

## app/Support/Delivery/

- `CityNameNormalizer.php` — Shared city-name normalization + alias dictionary, used by BOTH the (~560 tok)

## app/View/Components/


## bootstrap/


## bootstrap/cache/


## config/

- `inventory.php` (~265 tok)
- `sync.php` (~226 tok)

## database/


## database/factories/


## database/migrations/

- `2026_07_26_000001_add_variant_id_to_pos_order_items_table.php` — Migration: alter pos_order_items table (~294 tok)
- `2026_07_26_000002_add_variant_id_to_stock_ledger_table.php` — Migration: alter stock_ledger table (~276 tok)
- `2026_07_27_000001_create_stock_transfers_table.php` — Migration: create stock_transfers table (~719 tok)
- `2026_07_27_000002_create_stock_transfer_items_table.php` — Migration: create stock_transfer_items table (~391 tok)
- `2026_08_19_000001_add_shopify_webhook_fields_to_platform_connections_table.php` — Migration: alter platform_connections table (~216 tok)
- `2026_08_20_000001_add_position_to_product_attributes_tables.php` — Migration: alter product_attributes table (~237 tok)
- `2026_08_20_000002_create_product_publish_batches_and_results_tables.php` — Migration: create product_publish_batches table (~607 tok)
- `2026_08_21_000001_add_is_active_to_product_attribute_values_table.php` — Migration: alter product_attribute_values table (~159 tok)
- `2026_08_21_000001_create_product_sync_batches_and_results_tables.php` — Migration: create product_sync_batches table (~566 tok)
- `2026_08_22_000001_add_source_tracking_to_orders_table.php` — Phase OST2/OST3 — normalized order source metadata + indexes. (~1404 tok)
- `2026_08_22_000002_add_shortage_tracking_to_inventory_reservations_table.php` — Waiting Stock Reallocation phase — `inventory_reservations` is ALREADY the (~449 tok)
- `2026_08_24_000001_create_delivery_providers_table.php` — Catalogue of delivery carriers the platform knows how to integrate with. (~436 tok)
- `2026_08_24_000002_create_delivery_connections_table.php` — A store's credentials for one delivery provider (e.g. its Ozon Express (~642 tok)
- `2026_08_24_000003_create_delivery_provider_cities_table.php` — A provider's own city list, as synced from its API (e.g. Ozon's /cities). (~305 tok)
- `2026_08_24_000004_create_city_delivery_provider_mappings_table.php` — Links the platform's own `cities` (shared, provider-agnostic reference (~394 tok)
- `2026_08_24_000005_create_shipments_table.php` — The rich, provider-specific record of one order shipped through an (~893 tok)
- `2026_08_24_000006_create_shipment_events_table.php` — Append-only tracking history for one shipment. Never updated, only inserted. (~336 tok)
- `2026_08_24_000007_create_delivery_notes_table.php` — A carrier handover / "Bon de Livraison" batch, provider-side (Ozon's own BL, distinct from the inter (~385 tok)
- `2026_08_24_000008_create_delivery_note_shipments_table.php` — Migration: create delivery_note_shipments table (~234 tok)
- `2026_08_25_000001_add_pricing_fields_to_delivery_provider_cities_table.php` — Ozon's real /cities response carries REF and three price fields per city (~452 tok)
- `2026_08_25_000001_create_order_sync_batches_and_results_tables.php` — Mirrors product_sync_batches/product_sync_results (same queued-job + (~804 tok)
- `2026_08_25_000002_create_order_notifications_table.php` — One row per (user, order, type) — per-user "seen" state on purpose (the (~426 tok)

## database/seeders/


## docs/


## public/


## resources/css/


## resources/js/

- `app.jsx` — /*.jsx', { eager: true }); (~174 tok)

## resources/js/Components/

- `Button.jsx` — Codifies the button classes already used consistently across (~340 tok)
- `Card.jsx` — Thin wrapper for the `bg-surface-2 border border-line rounded-xl` pattern (~396 tok)
- `NotificationBell.jsx` — `notifications` is the live, server-polled list from (~1508 tok)
- `StatusBadge.jsx` — Tinted status chips. Background tint works in both modes; text darkens in (~1227 tok)
- `StoreSwitcher.jsx` — StoreSwitcher (~1755 tok)
- `SyncProductsModal.jsx` — DONE_STATUSES (~3475 tok)
- `ToastNotification.jsx` — `polled` is the live order-notification list from useOrderNotifications() — new ones toast once each (~1096 tok)
- `TypeBadge.jsx` — Same tinted-pill language as StatusBadge, for the two "what kind of thing (~353 tok)
- `UserDropdown.jsx` — UserDropdown (~1095 tok)

## resources/js/Components/Dashboard/

- `AdjustStockModal.jsx` — TABS (~9609 tok)

## resources/js/Components/Departments/

- `OperationsFilterBar.jsx` — Warehouse / city / assignee / client-org select filters for an operations queue. (~377 tok)
- `OperationsNav.jsx` — Switcher across the five single-station operations queues. (~955 tok)
- `OperationsTable.jsx` — Shared table body for the four order-based operations queues (~1390 tok)

## resources/js/Components/Filters/


## resources/js/Components/Onboarding/

- `Field.jsx` — Extracted from the original onboarding Wizard so every onboarding page shares one input style. (~360 tok)
- `OnboardingShell.jsx` — Shared page chrome for every onboarding screen — header, step circles, (~1141 tok)
- `Select.jsx` — Extracted from the original onboarding Wizard so every onboarding page shares one input style. (~404 tok)
- `WizardFooter.jsx` — Back / Skip / Continue row shared by every onboarding step. (~552 tok)

## resources/js/Components/Products/

- `AdjustStockModal.jsx` — Inventory-safe stock adjustment for the Product Edit page. Posts to (~2257 tok)
- `ImportProductsModal.jsx` — Small import-choice modal shown next to Add product / Sync / Add platform. (~1527 tok)
- `ProductCleanupBar.jsx` — Bulk cleanup action buttons + modals for imported products — archive / (~5722 tok)
- `PublishTargetModal.jsx` — Explicit publish-target selection — the fix for "clicking Publish pushes (~5749 tok)

## resources/js/Components/Settings/

- `SettingsNav.jsx` — Same "switcher across N related pages" pattern as DepartmentNav/OperationsNav. (~462 tok)

## resources/js/Hooks/

- `useCart.js` — initialState: reducer, clampPercent, lineSubtotal + 5 more (~2931 tok)
- `useOperationsFilters.js` — Warehouse / city / assigned-employee / client-organization filters layered (~432 tok)
- `useOrderNotifications.js` — Polls GET /dashboard/notifications/order-counts every 20s — the project (~615 tok)
- `useQueue.js` — Shared state for a department work queue. (~1013 tok)

## resources/js/Layouts/

- `AgencyLayout.jsx` — Lightweight shell for the agency workspace — deliberately not a second (~1087 tok)
- `AuthLayout.jsx` — Shared shell for the secondary auth screens (verify email, two-factor (~436 tok)
- `SaasLayout.jsx` — NAV_SECTIONS (~5144 tok)

## resources/js/Pages/


## resources/js/Pages/Admin/


## resources/js/Pages/Agency/

- `Clients.jsx` — Clients — renders form (~1181 tok)
- `ClientShow.jsx` — SERVICE_LABELS (~1460 tok)
- `Warehouses.jsx` — Warehouses — renders form (~1420 tok)

## resources/js/Pages/Auth/

- `ConfirmPassword.jsx` — Fortify's GET/POST /user/confirm-password. (~720 tok)
- `ForgotPassword.jsx` — Fortify's GET/POST /forgot-password (PasswordResetLinkController). (~864 tok)
- `Login.jsx` — Full-page login — Fortify's own POST /login (AuthenticatedSessionController), (~2845 tok)
- `ResetPassword.jsx` — Fortify's GET /reset-password/{token}, POST /reset-password (NewPasswordController). (~1520 tok)
- `TwoFactorChallenge.jsx` — Fortify's GET/POST /two-factor-challenge. (~1254 tok)
- `VerifyEmail.jsx` — Fortify's GET /email/verify — resend hits POST /email/verification-notification. (~646 tok)

## resources/js/Pages/Dashboard/

- `Index.jsx` — Index (~6538 tok)
- `Stock.jsx` — Stock (~7110 tok)
- `StockMovements.jsx` — TYPE_STYLES — renders table (~1675 tok)
- `StockTransferCreate.jsx` — KINDS — renders form (~7323 tok)
- `StockTransfers.jsx` — KIND_BADGE — renders table (~2880 tok)

## resources/js/Pages/Dashboard/Delivery/

- `Connections.jsx` — Derives the 5-way UI status from a mapped row and/or a raw suggestion object. (~6176 tok)

## resources/js/Pages/Dashboard/Departments/

- `Confirmation.jsx` — Confirmation desk — the 'Pending confirmation' queue. (~5793 tok)
- `Dispatch.jsx` — Dispatch board — packed orders waiting for a carrier, and everything in flight. (~10378 tok)
- `Packing.jsx` — Pick & pack bench — confirmed online orders and delivery-bound POS orders in (~5616 tok)

## resources/js/Pages/Dashboard/Integrations/

- `ConnectionProfile.jsx` — PLATFORM_LABELS (~7218 tok)
- `Index.jsx` — ICONS (~2027 tok)

## resources/js/Pages/Dashboard/Integrations/Platforms/

- `Shopify.jsx` — Real-API-truth diagnostics — replaces the old generic "Test connection" (~6360 tok)

## resources/js/Pages/Dashboard/Operations/

- `Packing.jsx` — Picked orders being boxed up for handover — status = packing only. (~1157 tok)
- `Picking.jsx` — Orders ready to pick, plus those currently being picked. (~1718 tok)
- `ReadyForDelivery.jsx` — Packed orders staged for handover. Carrier assignment stays on the existing (~1301 tok)
- `TransferReceiving.jsx` — Inbound InventoryTransfer rows awaiting receipt at a warehouse this org runs. (~1640 tok)
- `WaitingForStock.jsx` — Orders confirmed but blocked on missing stock — with the line-level (~5432 tok)

## resources/js/Pages/Dashboard/Orders/

- `Index.jsx` — STATUS_OPTIONS — renders table (~2556 tok)
- `Index.jsx` — Unified POS+online orders list; Source/Status filters, origin badges, view/receipt actions (~1600 tok)
- `Manage.jsx` — COLUMNS (~13667 tok)
- `Manage.jsx` — Multi-channel fulfillment board (Kanban+table); dept/source tabs, drawer transitions (~7000 tok)
- `Show.jsx` — Show — renders table (~3496 tok)
- `ShowOnline.jsx` — Pre-send visibility into how "Send to Ozon" would resolve this order's city — helps debug "not mappe (~4544 tok)
- `ShowOnline.jsx` — Online order detail: generate/view A4 invoice + print thermal receipt (~1700 tok)

## resources/js/Pages/Dashboard/Orders/Returns/

- `Index.jsx` — REASON_LABELS — renders table (~2238 tok)

## resources/js/Pages/Dashboard/Products/

- `Create.jsx` — Create — renders form (~5309 tok)
- `Edit.jsx` — Edit (~15480 tok)
- `Index.jsx` — Index (~4547 tok)

## resources/js/Pages/Dashboard/Roles/


## resources/js/Pages/Dashboard/Settings/


## resources/js/Pages/Dashboard/Stores/

- `Create.jsx` — Organization-first "Add Store": the workspace is never invented here — it (~2696 tok)
- `Edit.jsx` — Edit — renders form (~1753 tok)
- `Index.jsx` — TYPE_LABELS (~1862 tok)

## resources/js/Pages/Dashboard/Warehouses/

- `Index.jsx` — Owner vs. operator organization — the two are often the same org (a (~1687 tok)

## resources/js/Pages/Delivery/


## resources/js/Pages/Onboarding/

- `Agency.jsx` — TITLES (~6878 tok)
- `Merchant.jsx` — STEPS (~4478 tok)
- `ModeSelect.jsx` — The literal first onboarding question — "How will you use the platform?" (~639 tok)

## resources/js/Pages/Pos/

- `Dashboard.jsx` — stockStatus (~4035 tok)

## resources/js/Pages/Pos/Components/

- `Cart.jsx` — Cart (~2199 tok)
- `CartItem.jsx` — CartItem (~1230 tok)
- `CheckoutPreviewModal.jsx` — Final confirmation before the sale is committed. Shows a clean summary of (~3024 tok)
- `ProductCard.jsx` — Pull the first image out of an array, JSON string, or plain string. (~1726 tok)
- `VariantModal.jsx` — Variant picker for a variable product. The cashier chooses one value per (~4036 tok)

## resources/js/Pages/Settings/

- `Appearance.jsx` — Purely a client-side preference (localStorage + prefers-color-scheme, (~717 tok)
- `Profile.jsx` — Profile — renders form (~1477 tok)
- `Security.jsx` — Security — renders form (~1257 tok)

## resources/views/

- `app.blade.php` — Blade template (~309 tok)

## resources/views/components/


## resources/views/documents/

- `bon-de-sortie.blade.php` — Blade template (~2277 tok)
- `online-receipt.blade.php` — 80mm thermal receipt for an online Order (delivery slip); reads invoiceLineItems/invoiceTotals (~600 tok)
- `online-receipt.blade.php` — Blade template (~850 tok)

## resources/views/emails/


## resources/views/flux/icon/


## resources/views/flux/navlist/


## resources/views/layouts/


## resources/views/layouts/app/


## resources/views/layouts/auth/


## resources/views/livewire/


## resources/views/livewire/layout/


## resources/views/livewire/orders/


## resources/views/livewire/pages/auth/


## resources/views/livewire/products/


## resources/views/livewire/stores/


## resources/views/livewire/stores/settings/


## resources/views/livewire/welcome/


## resources/views/meta/


## resources/views/pages/auth/


## resources/views/pages/settings/


## resources/views/pages/settings/two-factor/


## resources/views/partials/


## resources/views/pos/documents/


## routes/

- `api.php` (~312 tok)
- `auth.php` (~1236 tok)
- `console.php` (~339 tok)
- `dashboard.php` (~6967 tok)
- `settings.php` (~380 tok)
- `web.php` — ============================================ (~1220 tok)

## storage/app/


## storage/app/private/


## storage/app/public/


## storage/framework/


## storage/framework/cache/


## storage/framework/cache/data/


## storage/framework/sessions/


## storage/framework/testing/


## storage/framework/views/


## storage/logs/


## tests/

- `Pest.php` — add-parcel returning a tracking number is never trusted alone — (~675 tok)

## tests/Feature/

- `_SmokeCheck.php` (~518 tok)

## tests/Feature/Api/


## tests/Feature/Auth/

- `AuthenticationTest.php` (~406 tok)
- `EmailVerificationTest.php` (~376 tok)
- `PasswordConfirmationTest.php` (~248 tok)
- `PasswordResetTest.php` (~500 tok)
- `RegistrationTest.php` (~164 tok)

## tests/Feature/Delivery/

- `DeliveryBoardOzonShipmentTest.php` — boardDispatcher: boardOrder (~1724 tok)
- `DeliveryCityMappingResolverTest.php` — Declares makeUnroutedOrder (~2164 tok)
- `DeliveryProviderCityUiPropsTest.php` (~2362 tok)
- `DeliveryProviderFoundationTest.php` (~809 tok)
- `OzonCityMappingBulkTest.php` — Declares bulkTestManager (~1848 tok)
- `OzonCityMappingSuggestionTest.php` — suggestionFor: ozonCity (~2102 tok)
- `OzonCityMappingTest.php` (~1232 tok)
- `OzonCitySyncTest.php` — Declares ozonManager (~3148 tok)
- `OzonConnectionStatusTest.php` — Declares statusTestManager (~2198 tok)
- `OzonConnectionTest.php` — Declares makeManager (~1704 tok)
- `OzonCreateShipmentResponseParsingTest.php` (~1966 tok)
- `OzonCreateShipmentTest.php` (~2245 tok)
- `OzonDeliveryNoteTest.php` (~1328 tok)
- `OzonParcelPriceFormatTest.php` (~1211 tok)
- `OzonParcelStockModeTest.php` — A real local Product matching the SKU, so the order line resolves instead of tripping the "unmapped (~3239 tok)
- `OzonProductsPayloadTest.php` — productsPayloadCallPrivate: productsPayloadResolveRef, productsPayloadBaseLine, productsPayloadDispa (~3102 tok)
- `OzonProviderErrorHandlingTest.php` (~1698 tok)
- `OzonSendShipmentCityMappingTest.php` — cityMappingTestDispatcher: readyOrderWithRawCity (~1915 tok)
- `OzonShipmentParameterSemanticsTest.php` — paramSemanticsDispatcher: paramSemanticsConnection, paramSemanticsOrder (~3904 tok)
- `OzonShipmentUiErrorTest.php` — Declares uiErrorTestDispatcher (~1830 tok)
- `OzonShipmentUiPropsTest.php` (~1324 tok)
- `OzonShipmentVerificationTest.php` — verificationDispatcher: verificationOrder (~4340 tok)
- `OzonTrackingTest.php` (~2234 tok)

## tests/Feature/Foundation/

- `AdminOperationsNavigationClarityTest.php` — Admin Operations Navigation Clarity — an admin (privileged store owner) (~1891 tok)
- `AgencyNavigationSeparationTest.php` — Declares agencyNavWorkspace (~767 tok)
- `AgencyOperationsNavigationTest.php` — Agency Operations Navigation — an agency admin operating a shared (~1926 tok)
- `AgencyOrderSourceScopeTest.php` — Phase OST6 — an agency admin filtering by source (platform/connection) (~1809 tok)
- `ChannelFrontendCoverageTest.php` — Declares channelCoverageWorkspace (~1285 tok)
- `CityWarehouseAllocationShortageTest.php` — City-to-warehouse allocation, including the "no mapping found" fallback (~2635 tok)
- `ConfirmationAddressPrefillTest.php` — Confirmation Desk address prefill — the customer's original (~2861 tok)
- `ConfirmationCityWarehouseSelectionTest.php` — Confirmation Desk city selection — the city dropdown is preselected from (~2636 tok)
- `ConfirmationDeskClaimTest.php` — Confirmation Desk claim-gated actions — an order must be claimed by the (~3527 tok)
- `ConnectionAuthClarityTest.php` — cacWorkspace: cacWoo (~2054 tok)
- `ConnectionOrderSyncBatchTest.php` — cosbWorkspace: cosbWoo (~1775 tok)
- `ConnectionProductArchiveTest.php` — cpaWorkspace: cpaWoo, cpaShopify, cpaProduct (~1526 tok)
- `ConnectionProfileTest.php` — cpWorkspace: cpWoo, cpShopifyClientCredentials (~1704 tok)
- `ConnectionScopeTest.php` — csWorkspace: csWoo (~1431 tok)
- `ConnectionSyncResetTest.php` — csrWorkspace: csrWoo, csrShopify (~2982 tok)
- `DashboardNavigationVisibilityTest.php` — navWorkspace: navMemberWithRole (~1588 tok)
- `ExternalStockPushJobTest.php` — Phase S6 — ExternalStockPushJob is the optional async wrapper around (~2166 tok)
- `InventoryEngineTest.php` — inventoryMerchant: inventoryProduct (~4304 tok)
- `NewOrderNotificationTest.php` — nonWorkspace: nonMember, nonShopifyWebhook (~1854 tok)
- `OnlineOrderLineInventoryResolverTest.php` — OrderLineInventoryResolver — the single, platform-agnostic resolver for (~2807 tok)
- `OnlineOrderReservationPolicyTest.php` — Phase O2 — online order reservation policy. Default: a pending (~2205 tok)
- `OperationsNavigationTest.php` — opsNavWorkspace: opsNavMember (~1082 tok)
- `OrderExternalStockSyncTest.php` — Phase O6 — every order/POS/return event that changes SELLABLE available (~2380 tok)
- `OrderInventoryConsistencyTest.php` — Phase O1/O8 — end-to-end online-order inventory lifecycle consistency: (~2220 tok)
- `OrderLineInventoryMappingTest.php` — Phase O4 — order line inventory resolution: ProductVariantChannelListing (~2411 tok)
- `OrderNotificationBadgeTest.php` — onbWorkspace: onbMember, onbShopifyWebhook (~2086 tok)
- `OrderSourceFilteringTest.php` — Phase OST5/OST6 — the Orders index filters by source_type (pos/online), (~1970 tok)
- `OrderSourceTrackingTest.php` — Phase OST2/OST4 — every order carries normalized, queryable source (~2466 tok)
- `OrderSourceUiPropsTest.php` — Phase OST5 — the Confirmation Desk queue and the order detail page both (~1394 tok)
- `OrderSyncIncrementalTest.php` — WooCommerceConnector::getOrders() sends `after` as a GET query param, not a form/JSON body — Http::R (~2197 tok)
- `OrderSyncQueueTest.php` — osqWorkspace: osqWooConnection (~1662 tok)
- `OrderWebhookIdempotencyTest.php` — Declares owiWorkspace (~1517 tok)
- `PosInventoryWorkflowTest.php` — Phase O3 — POS stock semantics via InventoryEngine (organization-backed (~2750 tok)
- `ProductArchiveTest.php` — pclAWorkspace: pclAProduct (~903 tok)
- `ProductBulkCleanupTest.php` — pclBMerchant: pclBWoo (~5474 tok)
- `ProductBulkPublishTest.php` — bpWorkspace: bpWooConnection, bpProduct, bpListing (~1672 tok)
- `ProductChannelUnlinkTest.php` — pclUWorkspace: pclUWoo, pclUShopify, pclUProduct (~1876 tok)
- `ProductCrossChannelMappingTest.php` — xchanWorkspace: xchanShopify, xchanWoo, xchanImportFromShopify, xchanImportFromWoo (~3830 tok)
- `ProductEditStockAdjustmentTest.php` — Declares adjustStockWorkspace (~1866 tok)
- `ProductEditVariantStockDisplayTest.php` — The Product Edit variant table must show the inventory engine's source of (~2616 tok)
- `ProductFrontendCoverageTest.php` — Declares pfcWorkspace (~1258 tok)
- `ProductImportEntryPointTest.php` — Declares importEntryPointWorkspace (~583 tok)
- `ProductPublishCanonicalPathTest.php` — Phase S1 — queued publish (/publish-queued) is the official UI publish (~1432 tok)
- `ProductPublishJobTest.php` — publishJobWorkspace: publishJobProduct, publishJobWooConnection (~2797 tok)
- `ProductPublishReadinessTest.php` — readinessWorkspace: readinessProduct (~1645 tok)
- `ProductPublishTargetingTest.php` — ptWorkspace: ptWooConnection, ptShopifyConnection, ptProduct, ptListing (~4185 tok)
- `ProductPurgeSafetyTest.php` — pclSMerchant: pclSProduct, pclSPosOrder (~2954 tok)
- `ProductQueuedSyncTest.php` — Phase S3 — /products/sync/start queues one ProductSyncJob per connection (~1806 tok)
- `ProductRepairCommandTest.php` — Phase S7 — catalog:diagnose-product / catalog:repair-product. Diagnostics (~2880 tok)
- `ProductResyncResetTest.php` — pclRWorkspace: pclRWoo (~1754 tok)
- `ProductSimpleVariableStateConsistencyTest.php` — product.type is authoritative: when a product becomes simple — whether (~2116 tok)
- `ProductStockSnapshotTest.php` — Phase S5 — ProductStockSnapshotService is the single source of truth for (~1501 tok)
- `ProductVariantCanonicalizationTest.php` — canonicalizationWorkspace: canonicalizationProduct (~2847 tok)
- `ProductVariantCrossChannelMappingTest.php` — xchanVariantWorkspace: xchanVariantWoo, xchanVariableProduct (~2358 tok)
- `ProductWizardDoesNotWriteStockTest.php` — Declares noWriteStockWorkspace (~1091 tok)
- `ProductWizardMigrationTest.php` — Declares pwmWorkspace (~2277 tok)
- `ProductWizardOptionValueRemovalTest.php` — removalWorkspace: removalProduct, removalSeedThreeColors (~3106 tok)
- `ProductWizardVariantPersistenceTest.php` — Declares wizardPersistenceWorkspace (~2330 tok)
- `ProductWizardVariantSkuGenerationTest.php` — skuGenWorkspace: skuGenProduct (~1836 tok)
- `ReturnInventoryEngineTest.php` — Phase O5 — return inspection through InventoryEngine (organization-backed (~2675 tok)
- `SettingsPageMigrationTest.php` (~381 tok)
- `ShopifyCanonicalPublishMapperTest.php` — shopifyMapperWorkspace: shopifyMapperProduct (~2150 tok)
- `ShopifyCapabilityDiagnosticsTest.php` — cdWorkspace: cdConnection, cdTokenFake (~2992 tok)
- `ShopifyClientCredentialsAuthTest.php` — sccWorkspace: sccConnection, sccFakeTokenResponse (~2800 tok)
- `ShopifyConnectionAuthStatusTest.php` — Root cause: ShopifyAuthService::testConnection() hard-gated on the (~2233 tok)
- `ShopifyConnectionWorkflowTest.php` — Declares scwWorkspace (~1993 tok)
- `ShopifyInventorySyncTest.php` — Shopify quantity is never set via a product/variant update payload — it (~3870 tok)
- `ShopifyOrderLineInventoryMappingTest.php` — Shopify order line -> local product/variant/InventoryItem mapping, via the (~2493 tok)
- `ShopifyOrderWebhookImportTest.php` — sowiWorkspace: sowiHeaders (~1927 tok)
- `ShopifyPublishMirrorsSaasProductTest.php` — The SaaS Product is the source of truth: publishing must mirror its (~2184 tok)
- `ShopifyPublishMirrorsSaasProductTest.php` — The SaaS Product is the source of truth: publishing must mirror its (~2161 tok)
- `ShopifySimpleDefaultVariantStrategyTest.php` — Phase S4 — consolidating regression test for the simple/variable Shopify (~2593 tok)
- `ShopifySimpleProductReadinessTest.php` — A product previously tested as variable in SaaS/Shopify, whose (~2596 tok)
- `ShopifySimpleSkuPublishTest.php` — Shopify SKU belongs to the variant, never the product parent — even a (~3015 tok)
- `ShopifySimpleToVariablePublishTest.php` — Covers the core bug: a Shopify-imported simple product is converted to (~3237 tok)
- `ShopifySimpleToVariablePublishTest.php` — Covers the core bug: a Shopify-imported simple product is converted to (~2877 tok)
- `ShopifyStockAdjustmentPushTest.php` — POST /dashboard/products/{product}/stock is the inventory-safe adjustment (~3193 tok)
- `ShopifyVariantInventorySyncTest.php` — A Shopify variant's stock lives on InventoryLevel (inventory_item_id + (~2927 tok)
- `ShopifyVariantSkuPublishTest.php` — For a variable product, SKU lives on each Shopify variant — publishing (~1461 tok)
- `ShopifyWebhookTest.php` — shopifyWebhookWorkspace: shopifyWebhookHeaders, shopifyProductPayload, shopifyOrderPayload (~2169 tok)
- `StoreCreationFoundationTest.php` — A real Store + membership under $organization, so the owner can actually (~1977 tok)
- `WaitingStockMappingRepairTest.php` — Waiting Stock mapping repair — a shortage line that was dropped entirely (~2364 tok)
- `WaitingStockReallocationTest.php` — Waiting Stock Reallocation — the core fix: an order confirmed with missing (~2502 tok)
- `WaitingStockShortageRequestTest.php` — Shortage/reclamation actions on the Waiting for Stock page: "Request (~2598 tok)
- `WaitingStockTransferFlowTest.php` — Transfer Receiving must stay empty until a transfer is genuinely (~1779 tok)
- `WaitingStockUiStateTest.php` — The UI-facing state machine (App\Support\WaitingStockState) and its use in (~2139 tok)
- `WaitingStockVariantMappingTest.php` — Root-cause regression coverage for the reported bug: a waiting order's (~2410 tok)
- `WooCommerceCanonicalPublishMapperTest.php` — wooMapperWorkspace: wooMapperProduct, wooMapperConnection (~2228 tok)
- `WooCommerceCanonicalPublishTest.php` — Phase S2 — the synchronous /publish endpoint now routes WooCommerce (~2099 tok)
- `WooCommerceOrderLineInventoryMappingTest.php` — WooCommerce order line -> local product/variant/InventoryItem mapping. (~2741 tok)
- `WooCommerceOrderWebhookImportTest.php` — wowiWorkspace: wowiHeaders, wowiOrderPayload (~2143 tok)
- `WooCommerceOutboundSyncTest.php` — wooOutboundWorkspace: wooOutboundProduct (~2995 tok)
- `WorkerOperationsNavigationTest.php` — An ordinary (non-admin) worker should mainly see the ONE workboard their (~1516 tok)

## tests/Feature/Invoicing/


## tests/Feature/Onboarding/

- `AgencyOnboardingTest.php` — Declares completeAgencyOrganizationStep (~1641 tok)
- `MerchantOnboardingTest.php` — completeMerchantOrganizationStep: completeMerchantStoreStep (~1199 tok)
- `OnboardingFlowTest.php` (~631 tok)

## tests/Feature/Orders/

- `OperationalQueueTest.php` — Same merchant/product helpers as FulfillmentWorkflowTest — one org, one (~5167 tok)
- `OrderChannelViewsTest.php` (~1510 tok)
- `OrderWorkflowServiceTest.php` (~4353 tok)
- `PosDeliveryRoutingTest.php` (~1315 tok)
- `ZZDebugTest.php` (~310 tok)

## tests/Feature/Roles/


## tests/Feature/Settings/

- `ProfileUpdateTest.php` (~483 tok)
- `SecurityTest.php` (~823 tok)

## tests/Feature/Stocks/

- `InventoryStockUiSnapshotTest.php` — Inventory Stock UI Operational Transparency — the Stock dashboard's props (~1266 tok)
- `StockAdjustmentFeedbackTest.php` — POST /dashboard/stock/{product}/adjust — the bulk Stock dashboard modal's (~1875 tok)
- `StockAdjustmentPreviewTest.php` — POST /dashboard/stock/{product}/preview-adjustment — read-only. Never (~1738 tok)
- `StockDashboardTest.php` (~1433 tok)
- `StockTransferTest.php` — Declares transferData (~2029 tok)
- `WaitingStockReleaseFeedbackTest.php` — Waiting Stock integration — after a stock adjustment releases a waiting (~1245 tok)

## tests/Feature/Team/


## tests/Unit/


## tests/Unit/Models/


## vendor/


## vendor/bacon/bacon-qr-code/


## vendor/bacon/bacon-qr-code/src/


## vendor/bacon/bacon-qr-code/src/Common/


## vendor/bacon/bacon-qr-code/src/Encoder/


## vendor/bacon/bacon-qr-code/src/Exception/


## vendor/bacon/bacon-qr-code/src/Renderer/


## vendor/bacon/bacon-qr-code/src/Renderer/Color/


## vendor/bacon/bacon-qr-code/src/Renderer/Eye/


## vendor/bacon/bacon-qr-code/src/Renderer/Image/


## vendor/bacon/bacon-qr-code/src/Renderer/Module/


## vendor/bacon/bacon-qr-code/src/Renderer/Module/EdgeIterator/


## vendor/bacon/bacon-qr-code/src/Renderer/Path/


## vendor/bacon/bacon-qr-code/src/Renderer/RendererStyle/


## vendor/bin/


## vendor/brianium/paratest/


## vendor/brianium/paratest/bin/


## vendor/brianium/paratest/src/


## vendor/brianium/paratest/src/Coverage/


## vendor/brianium/paratest/src/JUnit/

