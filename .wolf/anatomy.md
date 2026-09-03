# anatomy.md

> Auto-maintained by OpenWolf. Last scanned: 2026-09-03T20:31:44.051Z
> Files: 729 tracked | Anatomy hits: 0 | Misses: 0

## ../../../../../../laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/


## ../../../../.claude/jobs/a866b444/tmp/


## ../../../../.claude/plans/

- `cryptic-mixing-puzzle.md` — Role-Based Dashboards + Agent Activity Metrics Foundation (~4889 tok)
- `parallel-prancing-flame.md` — Delivery Provider Foundation + Ozon Express Integration (~4721 tok)
- `tidy-frolicking-moler.md` — Phase CV1 — Canonical Product Options and Variant Wizard Persistence (~3384 tok)

## ../../../../.claude/projects/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/memory/

- `feedback_implementation_first_no_test_runs.md` (~449 tok)
- `MEMORY.md` — Memory Index (~46 tok)

## ../../../../AppData/Local/Temp/


## ../../../../AppData/Local/Temp/claude/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/358ee1f2-0cee-456b-910c-65b6637b1237/scratchpad/

- `ReproBugTest.php` (~886 tok)

## ../../../../AppData/Local/Temp/claude/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/59bd1015-ad24-42de-a27d-7be499bdcc99/scratchpad/

- `fix_dispatch.js` — Declares fs (~2754 tok)
- `fix_dispatch.py` — Declares inputCls (~2733 tok)

## ../../../../AppData/Local/Temp/claude/C--Users-toshiba-Desktop-Work-Laravel-claude-saas-commerce/6f9a7a58-c73e-4236-80b8-4aaf138b3204/scratchpad/

- `sticky-test.html` — Declares html (~584 tok)

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

- `ShopifyConnector.php` — Shopify Admin REST API connector (version 2024-01). (~13048 tok)
- `WooCommerceConnector.php` — Parse WooCommerce product to normalized format (~9504 tok)

## app/Connectors/Delivery/

- `OzonExpressConnector.php` — Ozon Express Morocco (https://api.ozonexpress.ma). Auth is embedded in the (~10701 tok)
- `OzonStatusMapper.php` — Maps Ozon's raw tracking status strings to the shared normalized set. (~608 tok)
- `SenditConnector.php` — Sendit (https://app.sendit.ma/api/v1). Token auth: POST /login with (~7494 tok)
- `SenditStatusMapper.php` — Maps Sendit's documented raw status vocabulary to the shared normalized (~598 tok)

## app/Console/Commands/

- `CatalogCleanupPreviewCommand.php` — CatalogCleanupPreviewCommand: handle (~551 tok)
- `DiagnoseProductCommand.php` — DiagnoseProductCommand: handle (~358 tok)
- `DiagnoseSenditDistrictsCommand.php` — Read-only diagnostic for a Sendit district sync — never calls Sendit (~689 tok)
- `GenerateRecurringExpensesCommand.php` — GenerateRecurringExpensesCommand: handle (~245 tok)
- `PurgeImportedProductsCommand.php` — PurgeImportedProductsCommand: handle (~816 tok)
- `PurgeProductCommand.php` — PurgeProductCommand: handle (~595 tok)
- `RepairProductCommand.php` — RepairProductCommand: handle (~551 tok)

## app/Contracts/

- `DeliveryProviderConnectorInterface.php` — Contract every delivery provider connector implements (Ozon Express first). (~729 tok)

## app/Enums/

- `EmployeeAdvanceStatus.php` — EmployeeAdvanceStatus: label (~150 tok)
- `EmployeeEmploymentStatus.php` — EmployeeEmploymentStatus: label (~124 tok)
- `EmployeeRoleType.php` — EmployeeRoleType: label (~237 tok)
- `FinanceAccountType.php` — FinanceAccountType: label (~169 tok)
- `FinanceCodCollectabilityStatus.php` — Whether a COD order's receivable can actually be acted on right now. (~1160 tok)
- `FinanceCodSettlementStatus.php` — actual_received_amount differs from expected_net_amount and needs a note/investigation. (~270 tok)
- `FinanceCourierDepositStatus.php` — FinanceCourierDepositStatus: label (~110 tok)
- `FinanceDeliveryFeeSource.php` — Where a Shipment's fee snapshot came from — see FinanceDeliveryProviderFeeCalculator. (~279 tok)
- `FinanceDocumentType.php` — External legal proof — an expense with at least one document of one of (~544 tok)
- `FinanceExpenseJustificationStatus.php` — Live snapshot of how well-documented an expense currently is — recomputed (~248 tok)
- `FinanceExpenseJustificationType.php` — What the user declared at creation time about proof of this expense — the (~236 tok)
- `FinanceExpenseOwnerReviewStatus.php` — Owner/admin review of a non-official-document expense's justification. (~218 tok)
- `FinanceExpenseStatus.php` — FinanceExpenseStatus: label (~103 tok)
- `FinancePaymentMethod.php` — FinancePaymentMethod: label (~165 tok)
- `FinancePayoutFrequency.php` — Real providers that pay every ~24h — one period per calendar day. See FinanceCodPayoutPeriodService. (~616 tok)
- `FinanceRecurringFrequency.php` — Advance a due date to the next occurrence for this frequency. (~245 tok)
- `FinanceRecurringStatus.php` — FinanceRecurringStatus: label (~106 tok)
- `FinanceTransactionDirection.php` — Which way cash moves. `Neutral` records a fact (a sale happened, a receivable was created) without m (~132 tok)
- `FinanceTransactionType.php` — Informational only — the carrier fee this settlement's cash-in amount already nets out. Never moves (~1285 tok)
- `FulfillmentDocumentStatus.php` — Lifecycle of one `fulfillment_documents` row — did we actually get bytes on disk? (~335 tok)
- `FulfillmentDocumentType.php` — Kind of fulfilment paperwork stored in `fulfillment_documents`. (~323 tok)
- `PayrollItemStatus.php` — PayrollItemStatus: label (~123 tok)
- `PayrollPeriodStatus.php` — PayrollPeriodStatus: label (~144 tok)
- `SalaryPaymentFrequency.php` — SalaryPaymentFrequency: label (~107 tok)
- `SalaryType.php` — SalaryType: label (~162 tok)

## app/Events/


## app/Exceptions/


## app/Factories/

- `DeliveryConnectorFactory.php` — Instantiates the correct delivery-provider connector for a connection — (~315 tok)

## app/Http/Controllers/


## app/Http/Controllers/Admin/


## app/Http/Controllers/Api/

- `SenditWebhookController.php` — Receives Sendit's delivery-status-update webhook. Guest route (see (~587 tok)
- `ShopifyWebhookController.php` — Single endpoint for every Shopify webhook topic — the event is read (~2348 tok)
- `WooCommerceWebhookController.php` — Single endpoint for every WooCommerce order webhook topic — mirrors (~1653 tok)

## app/Http/Controllers/Auth/


## app/Http/Controllers/Dashboard/

- `ConnectionProfileController.php` — A per-connection "control panel" — the single place to check auth status, (~9979 tok)
- `DashboardController.php` — /dashboard renders a DIFFERENT dashboard depending on the viewer's role — (~3031 tok)
- `DeliveryConnectionController.php` — Delivery provider connection settings — Ozon Express first. (~2962 tok)
- `DeliveryNoteController.php` — create, addShipments, save (~707 tok)
- `DeliveryShipmentController.php` — Sending a packed order to an external delivery provider, and refreshing its tracking. (~1966 tok)
- `DepartmentController.php` — Focused work queues, one per operational department. (~7336 tok)
- `FulfillmentDocumentController.php` — Generating and downloading fulfilment paperwork (Ozon BL + carrier (~958 tok)
- `IntegrationsController.php` — Topics currently wired up end to end (Shopify Integration Workflow Upgrade). (~6435 tok)
- `OperationsController.php` — Focused, single-station queues layered over the existing department (~1642 tok)
- `OrderController.php` — Unified orders list — POS and online in one filterable, paginated table. (~6503 tok)
- `OrderNotificationController.php` — Lightweight polling endpoint for order badges/toasts — no websockets/ (~1054 tok)
- `ProductCleanupController.php` — Safe bulk cleanup for imported products — archive, unlink a platform (~1768 tok)
- `ProductController.php` — index, syncFromPlatform, create, store (~10548 tok)
- `ProductSyncController.php` — كيجيب الـ Connections المتاحة بحال لي كان ف دالة render() (~1324 tok)
- `SenditConnectionController.php` — Sendit connection settings, district sync, and district mapping — the (~3771 tok)
- `SettingsController.php` — Store-level brand appearance (primary/accent color, font, radius) — (~868 tok)
- `StockController.php` — id => name for the active store's sellable warehouses (set per request). (~9900 tok)
- `StockTransferController.php` — index, create, store, slip (~2839 tok)
- `StoreController.php` — Add Store is Organization-first: it never invents a workspace. It shows (~2348 tok)
- `WarehouseController.php` — index, create, store, edit, update (~1879 tok)

## app/Http/Controllers/Dashboard/Finance/

- `DeliveryProviderFinanceSettingController.php` — Simple per-organization finance setup for external delivery providers — (~3131 tok)
- `FinanceAccountController.php` — index, store, update, destroy (~628 tok)
- `FinanceCodReceivableController.php` — LOCAL/TESTING ONLY — never reachable in production (see the guard (~4044 tok)
- `FinanceCodSettlementController.php` — Attach the accountant's bank-transfer verification to an existing draft and finalize it — see Financ (~989 tok)
- `FinanceCourierDepositController.php` — store, confirm, cancel (~447 tok)
- `FinanceDashboardController.php` — index (~204 tok)
- `FinanceDocumentController.php` — Read/delete access to an existing FinanceDocument. Route-model binding on (~674 tok)
- `FinanceExpenseCategoryController.php` — index, store, update, destroy (~638 tok)
- `FinanceExpenseController.php` — Owner/admin review of an internal cash voucher / no-invoice expense — see FinanceExpensePolicy::revi (~2516 tok)
- `FinanceExpenseDocumentController.php` — Attaches one or more supporting documents to an expense. Allowed for a (~422 tok)
- `FinanceMonthlyStatementController.php` — index (~369 tok)
- `FinanceRecurringExpenseController.php` — index, create, store, edit, update + 3 more (~1105 tok)
- `FinanceTransactionController.php` — index, store (~926 tok)
- `FinanceVendorController.php` — index, store, update, destroy (~523 tok)
- `PayrollController.php` — index, create, store, show, calculate + 2 more (~1135 tok)
- `PayrollItemController.php` — update, applyAdvance, pay, cancel (~684 tok)

## app/Http/Controllers/Dashboard/Payroll/

- `EmployeeAdvanceController.php` — Creating an advance request never touches the ledger — see EmployeeAdvanceService::create(). (~695 tok)
- `EmployeeController.php` — index, create, store, edit, update + 4 more (~1571 tok)

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

- `HandleInertiaRequests.php` — HandleInertiaRequests: version, share (~1474 tok)

## app/Http/Requests/Auth/


## app/Http/Requests/Finance/

- `DeliveryProviderCityFeeRequest.php` — A city fee must be attached to a real city — never raw text. Exactly one (~814 tok)
- `DeliveryProviderFinanceSettingRequest.php` — DeliveryProviderFinanceSettingRequest: authorize, rules (~394 tok)
- `FinanceAccountRequest.php` — FinanceAccountRequest: authorize, rules (~378 tok)
- `FinanceCodCollectRequest.php` — FinanceCodCollectRequest: authorize, rules (~265 tok)
- `FinanceCodSettlementReconcileRequest.php` — FinanceCodSettlementReconcileRequest: authorize, rules, messages (~467 tok)
- `FinanceCodSettlementRequest.php` — FinanceCodSettlementRequest: authorize, rules (~408 tok)
- `FinanceCodSettlementVerifyPeriodRequest.php` — FinanceCodSettlementVerifyPeriodRequest: authorize, rules (~394 tok)
- `FinanceCourierDepositRequest.php` — FinanceCourierDepositRequest: authorize, rules (~359 tok)
- `FinanceDocumentUploadRequest.php` — Uploading is authorized exactly like editing the parent expense (~402 tok)
- `FinanceExpenseCategoryRequest.php` — FinanceExpenseCategoryRequest: authorize, rules (~326 tok)
- `FinanceExpenseRequest.php` — A request that never mentions justification_type at all (every (~1313 tok)
- `FinanceRecurringExpenseRequest.php` — FinanceRecurringExpenseRequest: authorize, rules (~586 tok)
- `FinanceTransactionAdjustmentRequest.php` — FinanceTransactionAdjustmentRequest: authorize, rules (~363 tok)
- `FinanceVendorRequest.php` — FinanceVendorRequest: authorize, rules (~227 tok)

## app/Http/Requests/Payroll/

- `EmployeeAdvanceRequest.php` — EmployeeAdvanceRequest: authorize, rules (~189 tok)
- `EmployeeRequest.php` — EmployeeRequest: authorize, rules (~528 tok)
- `EmployeeSalaryProfileRequest.php` — EmployeeSalaryProfileRequest: authorize, rules (~280 tok)
- `PayrollItemUpdateRequest.php` — PayrollItemUpdateRequest: authorize, rules (~159 tok)
- `PayrollPeriodRequest.php` — PayrollPeriodRequest: authorize, rules (~226 tok)

## app/Jobs/

- `ExternalStockPushJob.php` — Phase S6 — pushes a product's (or one variant's) already-committed local (~1422 tok)
- `OrderSyncJob.php` — Imports one PlatformConnection's orders in the background and records the (~1392 tok)
- `ProductPublishJob.php` — Publishes one canonical Product to one PlatformConnection and records the (~1147 tok)
- `ProductSyncJob.php` — Imports one PlatformConnection's catalog into the store and records the (~960 tok)
- `RecheckWaitingStockOrdersJob.php` — Dispatched by InventoryEngine whenever available stock increases at a (~474 tok)
- `ShopifyOrderWebhookJob.php` — Processes one verified Shopify orders/create|updated webhook off the (~893 tok)
- `TrackActiveShipmentsJob.php` — Scheduled poll of every non-terminal shipment (any provider), grouped by connection for bulk trackin (~469 tok)

## app/Jobs/Pos/


## app/Listeners/

- `CreateNewOrderNotifications.php` — Fires once per genuinely NEW order (OrderCreated is only dispatched from (~744 tok)
- `SyncFinanceOrderTransactions.php` — Bridges every newly-synced/imported online order into the Finance ledger. (~248 tok)

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

- `AgentActivityEvent.php` — Append-only agent/operational activity ledger. See the creating migration (~763 tok)
- `AgentScoreRule.php` — Configurable points-per-event-type rule — points/bonus system FOUNDATION (~437 tok)
- `CityDeliveryProviderMapping.php` — Links an internal City to one provider's city (e.g. Ozon). (~198 tok)
- `DeliveryConnection.php` — A store's credentials + settings for one delivery provider (e.g. Ozon (~1287 tok)
- `DeliveryNote.php` — A carrier handover batch (provider-side BL), distinct from the internal MAN- manifest system. (~366 tok)
- `DeliveryNoteShipment.php` — Model — 2 fields (~74 tok)
- `DeliveryProvider.php` — Every organization's finance setup for this provider — multiple rows (~237 tok)
- `DeliveryProviderCity.php` — One provider's city, as synced from its API (e.g. Ozon's /cities). (~388 tok)
- `DeliveryProviderCityFee.php` — A manual, organization-entered fee override for one (provider, city) pair — (~922 tok)
- `DeliveryProviderFinanceSetting.php` — An organization's own finance setup for one external delivery provider — (~562 tok)
- `Employee.php` — A person the business pays — with or without a system login (`user_id` (~799 tok)
- `EmployeeAdvance.php` — Available to be deducted from a future payroll run — paid out, not yet absorbed into a payslip, not (~580 tok)
- `EmployeeSalaryProfile.php` — One row per salary change — history, not a mutable "current salary" (~456 tok)
- `FinanceAccount.php` — Model — 7 fields, 3 rels (~334 tok)
- `FinanceCodSettlement.php` — A batch settlement from an external delivery company for a set of COD orders it collected on our beh (~752 tok)
- `FinanceCodSettlementItem.php` — Model — 5 fields, 2 rels (~208 tok)
- `FinanceCourierDeposit.php` — A cash handover from an internal delivery agent back to the accountant, for a set of COD orders they (~543 tok)
- `FinanceCourierDepositItem.php` — Model — 3 fields, 2 rels (~192 tok)
- `FinanceDocument.php` — A supporting document/justificatif attached to a Finance record (currently (~988 tok)
- `FinanceExpense.php` — Whether this expense's amount belongs in a fiscal/accountant-ready (~1319 tok)
- `FinanceExpenseCategory.php` — Model — 8 fields, 3 rels (~335 tok)
- `FinanceRecurringExpense.php` — Model — 17 fields, 5 rels (~748 tok)
- `FinanceTransaction.php` — Append-only cash/sales ledger. Never updated or deleted by application (~460 tok)
- `FinanceVendor.php` — Model — 7 fields, 3 rels (~334 tok)
- `FulfillmentDocument.php` — One piece of fulfilment paperwork — a fetched Ozon BL/label PDF, or a (~677 tok)
- `InventoryReservation.php` — Model — 13 fields, 4 rels (~383 tok)
- `Order.php` — Model — 47 fields, 4 rels (~2601 tok)
- `OrderNotification.php` — Per-user "new order" notification row — one per (user, order, type), see the migration's own doc com (~364 tok)
- `OrderSyncBatch.php` — Mirrors ProductSyncBatch — one row per queued "Sync orders now"/"Full order resync" action, summed f (~735 tok)
- `OrderSyncResult.php` — One row per (order sync batch, platform connection) — mirrors ProductSyncResult. (~390 tok)
- `PayrollItem.php` — One employee's salary-due line for one payroll period. Calculating a (~628 tok)
- `PayrollPeriod.php` — Model — 10 fields, 5 rels (~466 tok)
- `PlatformConnection.php` — The secret Shopify's HMAC signature must be verified against, no (~1351 tok)
- `PosOrderItem.php` — Model — 13 fields, 3 rels (~369 tok)
- `Product.php` — Platform-specific identities for this canonical catalog product. (~1797 tok)
- `ProductAttribute.php` — Model — 4 fields, 2 rels (~315 tok)
- `ProductAttributeValue.php` — Values the wizard should treat as real options — never a user-archived one. (~441 tok)
- `ProductPublishBatch.php` — Recompute counts/status from the batch's own result rows. (~627 tok)
- `ProductPublishResult.php` — Model — 10 fields, 4 rels (~383 tok)
- `ProductSyncBatch.php` — Recompute counts/status from the batch's own result rows. (~625 tok)
- `ProductSyncResult.php` — One row per (sync batch, platform connection) — a sync operates on a whole connection's catalog, not (~335 tok)
- `Shipment.php` — The rich, provider-specific shipment record (Ozon first). Separate from (~1933 tok)
- `ShipmentEvent.php` — Append-only tracking history for one shipment. (~236 tok)
- `StockLedger.php` — Model — table: stock_ledger, 12 fields, 5 rels (~368 tok)
- `StockTransfer.php` — A Stock Transfer / Bon de Sortie (exit slip): the authoritative record of goods (~816 tok)
- `StockTransferItem.php` — Model — 8 fields, 3 rels (~270 tok)
- `Store.php` — Model — 18 fields, 16 rels (~3193 tok)

## app/Models/Concerns/


## app/Models/Scopes/


## app/Notifications/


## app/Policies/

- `DeliveryProviderFinanceSettingPolicy.php` — Reuses the existing finance.view / finance.manage_cod_settlements permissions per the task spec — no (~351 tok)
- `EmployeeAdvancePolicy.php` — Approving/cancelling an advance request is an employees.manage action; PAYING one additionally needs (~296 tok)
- `EmployeePolicy.php` — EmployeePolicy: viewAny, view, create, update + 1 more (~314 tok)
- `FinanceAccountPolicy.php` — FinanceAccountPolicy: viewAny, view, create, update + 1 more (~330 tok)
- `FinanceCodSettlementPolicy.php` — FinanceCodSettlementPolicy: viewAny, view, create, update (~306 tok)
- `FinanceCourierDepositPolicy.php` — FinanceCourierDepositPolicy: viewAny, view, create, update (~302 tok)
- `FinanceDocumentPolicy.php` — Upload is authorized against the parent documentable's own policy (e.g. (~295 tok)
- `FinanceExpenseCategoryPolicy.php` — Tenancy + permission rules for expense categories. Every check first (~416 tok)
- `FinanceExpensePolicy.php` — Marking an already-paid expense back to unpaid is the sensitive direction. (~542 tok)
- `FinanceRecurringExpensePolicy.php` — FinanceRecurringExpensePolicy: viewAny, view, create, update + 1 more (~343 tok)
- `FinanceTransactionPolicy.php` — Manual adjustments only — automatic ledger writes go through the service layer directly. (~265 tok)
- `FinanceVendorPolicy.php` — FinanceVendorPolicy: viewAny, view, create, update + 1 more (~326 tok)
- `PayrollItemPolicy.php` — PayrollItemPolicy: view, update (~202 tok)
- `PayrollPeriodPolicy.php` — PayrollPeriodPolicy: viewAny, view, create, update (~286 tok)

## app/Providers/

- `AppServiceProvider.php` — Register any application services. (~1941 tok)
- `FortifyServiceProvider.php` — Register any application services. (~839 tok)

## app/Repositories/


## app/Services/


## app/Services/Activity/

- `AgentActivityRecorder.php` — The single write path for the agent/operational activity ledger (~524 tok)

## app/Services/Agency/

- `AgencyWorkspaceService.php` — AgencyWorkspaceService: createClient, createAgencyWarehouse, assignWarehouse, assignService (~1276 tok)

## app/Services/Catalog/

- `ProductCleanupSafetyService.php` — Read-only safety check for the product cleanup/resync-reset actions. (~3839 tok)
- `ProductCleanupService.php` — Mutating half of the imported-product cleanup toolkit. Every method takes (~2515 tok)
- `ProductDiagnosticService.php` — Phase S7 — read-only diagnostics + conservative repair for catalog data (~2100 tok)
- `ProductStockSnapshotService.php` — Single source of truth for "what stock should the UI show" — always (~2431 tok)
- `ProductVariantWizardService.php` — Turns a product-edit-wizard submission (option definitions + variant (~5300 tok)

## app/Services/Delivery/

- `DeliveryCityMappingResolver.php` — Resolves which Ozon (or any provider) city a packed order should ship to, (~2880 tok)
- `DeliveryCityMappingSuggestionService.php` — Conservative internal-city -> provider-city matching, for the "Map all (~1873 tok)
- `DeliveryNoteService.php` — Orchestrates Ozon's Bon de Livraison (delivery note) flow: create, add parcels, save, get PDFs. (~2765 tok)
- `FulfillmentDocumentService.php` — The single write path for `fulfillment_documents`. Purely operational (~2050 tok)
- `OzonCityMappingService.php` — OzonCityMappingService: syncCities, mapCity, mapAllSuggested, unmappedCities (~1160 tok)
- `OzonShipmentCreationException.php` — Thrown when Ozon rejects add-parcel or its response can't be parsed for a (~198 tok)
- `OzonShipmentService.php` — Sends a packed order to Ozon Express and records the result. (~5079 tok)
- `SenditDistrictMappingService.php` — Sendit's district-sync + internal-city mapping — mirrors (~2604 tok)
- `SenditShipmentCreationException.php` — Thrown when Sendit rejects POST /deliveries or its response can't be (~228 tok)
- `SenditShipmentService.php` — Sends a packed order to Sendit and records the result. Mirrors (~2546 tok)
- `SenditWebhookService.php` — Applies a Sendit webhook payload to the matching shipment. Signature (~929 tok)
- `ShipmentTrackingService.php` — Refreshes a shipment's tracking state (any provider, via (~1880 tok)

## app/Services/Finance/

- `FinanceAccountService.php` — FinanceAccountService: ensureSeeded, create, update, deactivate + 2 more (~1106 tok)
- `FinanceCodCollectabilityService.php` — Gates every COD cash action (mark collected, external settlement, (~2183 tok)
- `FinanceCodPayoutPeriodService.php` — Groups delivered, external-carrier COD orders into payout periods per the (~2426 tok)
- `FinanceCodSettlementDiagnosticsService.php` — Explains why a delivered, external-carrier COD order does NOT (yet) show (~1010 tok)
- `FinanceCodSettlementService.php` — External carrier COD settlement — a delivery company (Ozon, Sendit, or (~4025 tok)
- `FinanceCourierDepositService.php` — Internal courier cash deposit — a company employee/livreur who delivered (~2138 tok)
- `FinanceDashboardService.php` — Read-only metrics for the Finance Dashboard. (~2132 tok)
- `FinanceDeliveryProviderFeeCalculator.php` — Resolves what an external delivery provider is expected to charge for one (~3536 tok)
- `FinanceDocumentService.php` — The single write path for finance_documents. Purely evidentiary storage — (~1002 tok)
- `FinanceExpenseCategoryService.php` — Tenant-scoped expense category management. Default categories are seeded (~1138 tok)
- `FinanceExpenseService.php` — Official invoice/receipt = external legal proof (a FinanceDocument of one (~6495 tok)
- `FinanceMonthlyStatementService.php` — Builds the monthly finance statement. (~6720 tok)
- `FinanceOrderTransactionService.php` — Bridges the existing Orders/POS lifecycle into the Finance ledger, without (~5541 tok)
- `FinanceRecurringExpenseService.php` — Safety cap on catch-up iterations per recurring expense in a single run. (~2130 tok)
- `FinanceTransactionService.php` — The single write path for the finance ledger. Append-only: nothing here (~1281 tok)
- `FinanceVendorService.php` — A vendor already referenced by an expense/recurring expense is deactivated instead of deleted. (~407 tok)

## app/Services/Inventory/

- `AllocationCompletionService.php` — The single place that decides "this allocation's shortages are all (~558 tok)
- `CatalogInventoryService.php` — CatalogInventoryService: forCatalog, resolve (~1580 tok)
- `InventoryEngine.php` — InventoryEngine: setOnHand, adjustOnHand, reserve, release + 6 more (~3809 tok)
- `InventoryTransferService.php` — InventoryTransferService: request, approve, cancel, ship + 1 more (~2147 tok)
- `OrderLineInventoryResolution.php` — The outcome of resolving one online order line to a local catalog (~441 tok)
- `OrderLineInventoryResolver.php` — The single, platform-agnostic resolver for "what local catalog record — (~4312 tok)
- `WaitingStockReallocationService.php` — Waiting Stock Reallocation — recovers orders stuck in WaitingForStock once (~1427 tok)
- `WarehouseAllocationService.php` — WarehouseAllocationService: allocate, release, consume, restoreConsumed + 1 more (~5810 tok)

## app/Services/Invoicing/


## app/Services/Meta/


## app/Services/Metrics/

- `AgentDashboardMetricsService.php` — Read-only metrics for a single agent's own confirmation/fulfillment/ (~1856 tok)
- `AgentScorePreviewService.php` — "Performance points preview" — POINTS FOUNDATION ONLY, per the brief. Reads (~543 tok)
- `OwnerDashboardMetricsService.php` — The store owner/admin business-overview dashboard. `today_sales`, (~3390 tok)
- `SupervisorDashboardMetricsService.php` — Operations-control metrics: queue sizes, waiting-stock/delayed-order (~1032 tok)

## app/Services/Onboarding/

- `AgencyOnboardingService.php` — Backs the agency onboarding wizard (Step 8 / A1-A10). Reuses (~1765 tok)
- `MerchantOnboardingService.php` — Backs the merchant onboarding wizard (Step 8 / M1-M6). Each method is one (~1964 tok)

## app/Services/Orders/

- `DispatchService.php` — Handing a packed order to whoever carries it, and recording the outcome. (~5131 tok)
- `OperationsQueueService.php` — Cross-store operational queues, scoped by warehouse OPERATOR rather than the (~8097 tok)
- `OrderAssignmentService.php` — Who is working which order. (~1642 tok)
- `OrderWorkflowService.php` — Every fulfillment status change goes through here — the board, the WhatsApp (~4082 tok)
- `ReturnInspectionService.php` — Reverse logistics: receiving returned goods and routing each line to active or (~3895 tok)
- `StockMovementWriter.php` — The single place stock quantities are mutated by the order lifecycle. (~1156 tok)

## app/Services/Payroll/

- `EmployeeAdvanceService.php` — Requesting/approving an advance never touches the ledger — only actually (~2070 tok)
- `EmployeeSalaryService.php` — Salary is HISTORY, never a mutable "current amount" field — see (~788 tok)
- `EmployeeService.php` — Employees are a payroll concept, deliberately separate from (~1659 tok)
- `PayrollService.php` — Payroll periods/items are salary DUE, never cash on their own — (~3523 tok)

## app/Services/Pos/

- `DocumentGenerationService.php` — Render a finalized Facture to an A4 PDF and persist it. Returns the (~3833 tok)
- `OrderProcessingService.php` — Create a POS order with its line items. Runs in a single transaction so (~3783 tok)

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
- `ShopifyWebhookRegistrationService.php` — Ensures a Shopify connection's order webhooks (orders/create, (~934 tok)
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

- `BrandAppearance.php` — Single source of truth for store-level brand appearance (primary/accent (~639 tok)
- `InertiaErrorResponder.php` — Central place deciding how an uncaught exception becomes an HTTP response (~1006 tok)
- `OnboardingOptions.php` — Static option lists shared by every onboarding controller/page — kept in (~1309 tok)
- `OrderAddressSummary.php` — The customer's ORIGINAL shipping/delivery address, as the platform sent (~1958 tok)
- `OrderLineItems.php` — One line-item shape for both order models, for code that has to touch stock. (~1754 tok)
- `OrderPresenter.php` — Normalizes POS and online orders into one shape for the Order Management view, (~4026 tok)
- `OrderSourceSummary.php` — Phase OST — single source of truth for "where did this order come from", (~1428 tok)
- `PermissionCatalog.php` — Central catalogue of every granular permission a store role can grant. (~4463 tok)
- `WaitingStockState.php` — Single source of truth for "what should a waiting-stock order's badge say" (~979 tok)

## app/Support/Delivery/

- `CityNameNormalizer.php` — Shared city-name normalization + alias dictionary, used by BOTH the (~560 tok)

## app/View/Components/


## bootstrap/

- `app.php` (~749 tok)

## bootstrap/cache/


## config/

- `finance.php` (~238 tok)
- `fulfillment.php` (~274 tok)
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
- `2026_08_26_000001_add_sendit_delivery_provider.php` — Seeds Sendit into the delivery_providers catalogue — required before any (~258 tok)
- `2026_08_26_000002_add_generic_location_fields_to_delivery_provider_cities_table.php` — Generic (provider-agnostic) location fields, first needed by Sendit's (~509 tok)
- `2026_08_27_000001_add_district_name_fields_to_delivery_provider_cities_table.php` — Sendit's /districts rows carry TWO distinct name fields — `ville` (the (~405 tok)
- `2026_08_27_000002_add_pagination_diagnostics_to_delivery_connections_table.php` — Diagnostics for a district/city sync, alongside the existing (~433 tok)
- `2026_08_28_000001_create_agent_activity_events_table.php` — Append-only agent/operational activity ledger — the foundation for (~665 tok)
- `2026_08_28_000002_create_agent_score_rules_table.php` — Configurable points-per-event-type rules — the foundation for a future (~840 tok)
- `2026_08_29_000001_create_finance_expense_categories_table.php` — Migration: create finance_expense_categories table (~316 tok)
- `2026_08_29_000002_create_finance_vendors_table.php` — Migration: create finance_vendors table (~278 tok)
- `2026_08_29_000003_create_finance_recurring_expenses_table.php` — Migration: create finance_recurring_expenses table (~556 tok)
- `2026_08_29_000004_create_finance_expenses_table.php` — Migration: create finance_expenses table (~754 tok)
- `2026_08_30_000001_create_finance_accounts_table.php` — Migration: create finance_accounts table (~357 tok)
- `2026_08_30_000002_create_finance_transactions_table.php` — Migration: create finance_transactions table (~748 tok)
- `2026_08_31_000001_create_finance_cod_settlements_table.php` — A batch settlement from an external delivery company (Ozon, Sendit, or (~695 tok)
- `2026_08_31_000002_create_finance_cod_settlement_items_table.php` — Which pending COD orders are included in one external carrier settlement. (~329 tok)
- `2026_08_31_000003_create_finance_courier_deposits_table.php` — A cash handover from an internal delivery agent (employee/livreur) back (~646 tok)
- `2026_08_31_000004_create_finance_courier_deposit_items_table.php` — Which pending COD orders are included in one internal courier cash deposit. (~331 tok)
- `2026_09_01_000001_add_sequence_to_finance_transactions_table.php` — Some transaction types are genuinely repeatable over an entity's (~621 tok)
- `2026_09_01_000002_create_finance_documents_table.php` — Migration: create finance_documents table (~652 tok)
- `2026_09_02_000001_create_delivery_provider_finance_settings_table.php` — One row per (organization, delivery_provider) — `delivery_providers` is a (~659 tok)
- `2026_09_02_000002_create_delivery_provider_city_fees_table.php` — Manual, organization-entered per-city fee override for one delivery (~579 tok)
- `2026_09_02_000003_add_fee_snapshot_to_shipments_table.php` — Fee snapshot — computed ONCE (App\Services\Finance\ (~758 tok)
- `2026_09_02_000004_add_reconciliation_to_finance_cod_settlements_table.php` — Extends the existing ad-hoc external settlement (Phase 2) into a (~723 tok)
- `2026_09_02_000005_add_fee_snapshot_to_finance_cod_settlement_items_table.php` — Per-order fee audit trail — copied from the order's Shipment fee snapshot (~281 tok)
- `2026_09_03_000001_add_city_references_to_delivery_provider_city_fees_table.php` — Additive only — adds proper city references to `delivery_provider_city_fees` (~1148 tok)
- `2026_09_04_000001_add_justification_to_finance_expenses_table.php` — Internal justification / owner-review workflow for expenses paid without (~1146 tok)
- `2026_09_05_000001_rename_fuel_receipt_document_type.php` — FinanceDocumentType::FuelReceipt ('fuel_receipt') was renamed to FuelTicket (~213 tok)
- `2026_09_06_000001_create_employees_table.php` — Migration: create employees table (~627 tok)
- `2026_09_06_000002_create_employee_salary_profiles_table.php` — Salary HISTORY, not a single mutable row per employee — see (~555 tok)
- `2026_09_06_000003_create_payroll_periods_table.php` — Migration: create payroll_periods table (~434 tok)
- `2026_09_06_000004_create_payroll_items_table.php` — One salary-due line per employee per payroll period. Calculating a period (~824 tok)
- `2026_09_06_000005_create_employee_advances_table.php` — Migration: create employee_advances table (~605 tok)
- `2026_09_07_000001_create_fulfillment_documents_table.php` — Generic private store for fulfilment paperwork — Ozon Bon de Livraison (~733 tok)

## database/seeders/


## docs/


## public/


## resources/css/

- `app.css` — Styles: 15 rules, 100 vars (~4789 tok)

## resources/js/

- `app.jsx` — /*.jsx', { eager: true }); (~365 tok)

## resources/js/Components/

- `Button.jsx` — Codifies the button classes already used consistently across (~393 tok)
- `Card.jsx` — Thin wrapper for the `bg-surface-2 border border-line rounded-xl` pattern (~401 tok)
- `DataTable.jsx` — DataTable — renders table (~1061 tok)
- `NotificationBell.jsx` — `notifications` is the live, server-polled list from (~1574 tok)
- `SearchFilterBar.jsx` — isMac — renders form (~1497 tok)
- `Select.jsx` — Design-system replacement for a native select element — a fully custom, (~2530 tok)
- `StatsCard.jsx` — Tinted icon chips — subtle in light, vivid in dark. Text darkens in light for contrast. (~628 tok)
- `StatusBadge.jsx` — Tinted status chips. Background tint works in both modes; text darkens in (~1573 tok)
- `StoreSwitcher.jsx` — StoreSwitcher (~1755 tok)
- `SyncProductsModal.jsx` — DONE_STATUSES (~3480 tok)
- `ThemeToggle.jsx` — Clean icon toggle that flips between light and dark. (~284 tok)
- `ToastNotification.jsx` — `polled` is the live order-notification list from useOrderNotifications() — new ones toast once each (~1122 tok)
- `TypeBadge.jsx` — Same tinted-pill language as StatusBadge, for the two "what kind of thing (~353 tok)
- `UserDropdown.jsx` — UserDropdown (~1095 tok)

## resources/js/Components/Dashboard/

- `AdjustStockModal.jsx` — TABS (~9761 tok)

## resources/js/Components/Dashboard/Roles/

- `ConfirmationAgentDashboard.jsx` — ConfirmationAgentDashboard (~1187 tok)
- `DeliveryAgentDashboard.jsx` — DeliveryAgentDashboard (~1034 tok)
- `FulfillmentAgentDashboard.jsx` — FulfillmentAgentDashboard (~1070 tok)
- `InventoryDashboard.jsx` — InventoryDashboard (~521 tok)
- `OwnerDashboard.jsx` — The business-overview dashboard — byte-for-byte the same content that used (~4058 tok)
- `PointsPreviewCard.jsx` — "Performance points preview" — foundation only, per the brief. Never (~539 tok)
- `SupervisorDashboard.jsx` — QUEUE_META — renders table (~1469 tok)

## resources/js/Components/Departments/

- `DepartmentNav.jsx` — Switcher across the four operational departments. (~956 tok)
- `OperationsFilterBar.jsx` — Warehouse / city / assignee / client-org select filters for an operations queue. (~382 tok)
- `OperationsNav.jsx` — Switcher across the five single-station operations queues. (~978 tok)
- `OperationsTable.jsx` — Shared table body for the four order-based operations queues (~1389 tok)
- `QueueParts.jsx` — Small pieces shared by every department dashboard, so the four pages differ (~4280 tok)

## resources/js/Components/Filters/


## resources/js/Components/Finance/

- `CitySearchSelect.jsx` — Searchable city dropdown — never a free-text city input. `options` come (~1059 tok)
- `ExpenseDocumentPicker.jsx` — "Documents justificatifs" section for the Create Expense page. The expense (~1252 tok)
- `ExpenseDocumentsCard.jsx` — Documents/justificatifs card for the Expense Edit page. Upload/delete are (~2151 tok)
- `ExpenseForm.jsx` — PAYMENT_METHODS — renders form (~3144 tok)
- `JustificationBadges.jsx` — Justification/documentation badges for an expense row or the Edit page — (~845 tok)
- `RecurringExpenseForm.jsx` — FREQUENCIES — renders form (~1895 tok)

## resources/js/Components/Onboarding/

- `Field.jsx` — Extracted from the original onboarding Wizard so every onboarding page shares one input style. (~360 tok)
- `OnboardingShell.jsx` — Shared page chrome for every onboarding screen — header, step circles, (~1141 tok)
- `Select.jsx` — Extracted from the original onboarding Wizard so every onboarding page shares one input style. (~404 tok)
- `WizardFooter.jsx` — Back / Skip / Continue row shared by every onboarding step. (~552 tok)

## resources/js/Components/Payroll/

- `EmployeeForm.jsx` — EmployeeForm — renders form (~1465 tok)

## resources/js/Components/PremiumDashboard/

- `CommandPalette.jsx` — Cmd/Ctrl+K search over every nav item the current user can access (~984 tok)
- `CommandSearchBar.jsx` — Opens the existing CommandPalette (Cmd/Ctrl+K, searches every accessible (~554 tok)
- `ContextualModuleNav.jsx` — Module-scoped tabs for the topbar center — replaces the old fixed (~349 tok)
- `DashboardSkeleton.jsx` — Reduced-motion-aware loading skeleton for the premium dashboard composition. (~260 tok)
- `EmptyMetricState.jsx` — Honest empty state for metrics or series the backend does not provide. (~170 tok)
- `FloatingTopbar.jsx` — FloatingTopbar (~1682 tok)
- `FullNavigationDrawer.jsx` — Display grouping only — every underlying section/item label stays exactly (~3158 tok)
- `MiniChartCard.jsx` — Real-series mini chart renderer that falls back to an explicit unavailable-data state instead of invented values. (~520 tok)
- `PermissionAwareRail.jsx` — Compact floating icon dock — quick access only, curated per role by (~1519 tok)
- `PremiumAppShell.jsx` — PremiumAppShell (~401 tok)
- `PremiumMetricCard.jsx` — Green credit-card-inspired metric panel with reduced-motion-safe count-up behavior. (~780 tok)
- `QuickActionButton.jsx` — Reusable Inertia quick-action pill for existing routes. (~190 tok)
- `RecentOrdersCard.jsx` — Responsive recent-orders table using only DashboardController order props. (~850 tok)
- `SidebarHoverTrigger.jsx` — The ONLY hover-open surface for the full navigation drawer (besides the (~635 tok)
- `SoftCard.jsx` — Shared white soft-shadow dashboard surface. (~90 tok)
- `StatusPill.jsx` — Accessible order-status badge mapped to the existing backend statuses. (~280 tok)

## resources/js/Components/Products/

- `AdjustStockModal.jsx` — Inventory-safe stock adjustment for the Product Edit page. Posts to (~2257 tok)
- `ImportProductsModal.jsx` — Small import-choice modal shown next to Add product / Sync / Add platform. (~1527 tok)
- `ProductCleanupBar.jsx` — Bulk cleanup action buttons + modals for imported products — archive / (~5722 tok)
- `PublishTargetModal.jsx` — Explicit publish-target selection — the fix for "clicking Publish pushes (~5749 tok)

## resources/js/Components/Settings/

- `SettingsNav.jsx` — Same "switcher across N related pages" pattern as DepartmentNav/OperationsNav. (~462 tok)

## resources/js/Hooks/

- `useCart.js` — initialState: reducer, clampPercent, lineSubtotal + 5 more (~2931 tok)
- `useDensity.js` — Personal UI density preference — 'comfortable' | 'compact'. Mirrors (~454 tok)
- `useOperationsFilters.js` — Warehouse / city / assigned-employee / client-organization filters layered (~432 tok)
- `useOrderNotifications.js` — Polls GET /dashboard/notifications/order-counts every 20s — the project (~615 tok)
- `useQueue.js` — Shared state for a department work queue. (~1013 tok)

## resources/js/Layouts/

- `AgencyLayout.jsx` — Lightweight shell for the agency workspace — deliberately not a second (~1087 tok)
- `AuthLayout.jsx` — Shared shell for the secondary auth screens (verify email, two-factor (~436 tok)
- `SaasLayout.jsx` — NAV_SECTIONS (~4063 tok)

## resources/js/Pages/

- `Error.jsx` — Branded replacement for Laravel's bare framework error views — rendered (~951 tok)

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

- `AddMember.jsx` — AddMember — renders form (~2597 tok)
- `EditMember.jsx` — EditMember — renders form (~3496 tok)
- `Index.jsx` — /dashboard renders a different dashboard per role — see (~389 tok)
- `InviteMember.jsx` — InviteMember — renders form (~1850 tok)
- `Stock.jsx` — Stock (~7110 tok)
- `StockMovements.jsx` — TYPE_STYLES — renders table (~1675 tok)
- `StockTransferCreate.jsx` — KINDS — renders form (~7323 tok)
- `StockTransfers.jsx` — KIND_BADGE — renders table (~2880 tok)
- `Team.jsx` — RoleBadge — renders table (~1777 tok)

## resources/js/Pages/Dashboard/Delivery/

- `Connections.jsx` — Derives the 5-way UI status from a mapped row and/or a raw suggestion object. (~6249 tok)
- `SenditConnections.jsx` — citySync — renders form (~7696 tok)

## resources/js/Pages/Dashboard/Departments/

- `Confirmation.jsx` — Confirmation desk — the 'Pending confirmation' queue. (~5826 tok)
- `Dispatch.jsx` — Dispatch board — packed orders waiting for a carrier, and everything in flight. (~15235 tok)
- `Packing.jsx` — Pick & pack bench — confirmed online orders and delivery-bound POS orders in (~5668 tok)

## resources/js/Pages/Dashboard/Finance/

- `Dashboard.jsx` — money (~3097 tok)
- `MonthlyStatement.jsx` — money — renders table (~7647 tok)

## resources/js/Pages/Dashboard/Finance/Accounts/

- `Index.jsx` — TYPES — renders form, table (~2365 tok)

## resources/js/Pages/Dashboard/Finance/Categories/

- `Index.jsx` — Index — renders form, table (~1957 tok)

## resources/js/Pages/Dashboard/Finance/CodReceivables/

- `Index.jsx` — The ad-hoc "Mark collected" button is only ever shown ENABLED for a (~13067 tok)

## resources/js/Pages/Dashboard/Finance/DeliveryProviders/

- `Index.jsx` — money — renders form, table, modal (~6637 tok)

## resources/js/Pages/Dashboard/Finance/Expenses/

- `Create.jsx` — Create (~726 tok)
- `Edit.jsx` — Edit (~1864 tok)
- `Index.jsx` — money — renders table (~3371 tok)

## resources/js/Pages/Dashboard/Finance/Payroll/

- `Create.jsx` — Create — renders form (~1350 tok)
- `Index.jsx` — money — renders table (~839 tok)
- `Show.jsx` — money — renders form, table, modal (~3986 tok)

## resources/js/Pages/Dashboard/Finance/Recurring/

- `Create.jsx` — Create (~496 tok)
- `Edit.jsx` — Edit (~562 tok)
- `Index.jsx` — money — renders table (~1359 tok)

## resources/js/Pages/Dashboard/Finance/Transactions/

- `Index.jsx` — money — renders form, table (~3072 tok)

## resources/js/Pages/Dashboard/Finance/Vendors/

- `Index.jsx` — Index — renders form, table (~1994 tok)

## resources/js/Pages/Dashboard/Integrations/

- `ConnectionProfile.jsx` — PLATFORM_LABELS (~7251 tok)
- `Index.jsx` — ICONS (~2466 tok)

## resources/js/Pages/Dashboard/Integrations/Platforms/

- `Shopify.jsx` — Real-API-truth diagnostics — replaces the old generic "Test connection" (~6351 tok)
- `WhatsApp.jsx` — WhatsApp — renders form (~1372 tok)
- `WooCommerce.jsx` — WooCommerce — renders form (~1102 tok)
- `YouCan.jsx` — YouCan — renders form (~950 tok)

## resources/js/Pages/Dashboard/Operations/

- `Packing.jsx` — Picked orders being boxed up for handover — status = packing only. (~1171 tok)
- `Picking.jsx` — Orders ready to pick, plus those currently being picked. (~1748 tok)
- `ReadyForDelivery.jsx` — Packed orders staged for handover. Carrier assignment stays on the existing (~1306 tok)
- `TransferReceiving.jsx` — Inbound InventoryTransfer rows awaiting receipt at a warehouse this org runs. (~1659 tok)
- `WaitingForStock.jsx` — Orders confirmed but blocked on missing stock — with the line-level (~5432 tok)

## resources/js/Pages/Dashboard/Orders/

- `Index.jsx` — STATUS_OPTIONS — renders table (~2556 tok)
- `Index.jsx` — Unified POS+online orders list; Source/Status filters, origin badges, view/receipt actions (~1600 tok)
- `Manage.jsx` — COLUMNS (~15167 tok)
- `Manage.jsx` — Multi-channel fulfillment board (Kanban+table); dept/source tabs, drawer transitions (~7000 tok)
- `Show.jsx` — Show — renders table (~3496 tok)
- `ShowOnline.jsx` — Pre-send visibility into how "Send to Ozon" would resolve this order's city — helps debug "not mappe (~5318 tok)
- `ShowOnline.jsx` — Online order detail: generate/view A4 invoice + print thermal receipt (~1700 tok)

## resources/js/Pages/Dashboard/Orders/Returns/

- `Index.jsx` — REASON_LABELS — renders table (~2264 tok)
- `Inspect.jsx` — CONDITIONS (~4199 tok)

## resources/js/Pages/Dashboard/Payroll/Employees/

- `Create.jsx` — Create (~426 tok)
- `Edit.jsx` — money — renders form (~4507 tok)
- `Index.jsx` — Index — renders table (~1558 tok)

## resources/js/Pages/Dashboard/Products/

- `Create.jsx` — Create — renders form (~5360 tok)
- `Edit.jsx` — Edit (~15504 tok)
- `Index.jsx` — Index (~4546 tok)

## resources/js/Pages/Dashboard/Roles/

- `Form.jsx` — RoleForm — renders form (~2746 tok)
- `Index.jsx` — RolesIndex (~1489 tok)

## resources/js/Pages/Dashboard/Settings/

- `Index.jsx` — Index — renders form (~2050 tok)

## resources/js/Pages/Dashboard/Stores/

- `Create.jsx` — Organization-first "Add Store": the workspace is never invented here — it (~2737 tok)
- `Edit.jsx` — Edit — renders form (~1764 tok)
- `Index.jsx` — TYPE_LABELS (~1906 tok)

## resources/js/Pages/Dashboard/Warehouses/

- `Index.jsx` — Owner vs. operator organization — the two are often the same org (a (~1687 tok)

## resources/js/Pages/Delivery/

- `DeliveryAgentView.jsx` — Delivery agent view — a driver's own queue on a phone. (~6652 tok)

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

- `Appearance.jsx` — Unified Appearance page. Theme mode + density are personal, client-only (~4884 tok)
- `Profile.jsx` — Profile — renders form (~1462 tok)
- `Security.jsx` — Security — renders form (~1272 tok)

## resources/js/Support/

- `applyBrandTokens.js` — Curated, system-safe font stacks (Settings -> Appearance -> Font family). (~554 tok)
- `color.js` — Small, dependency-free color helpers for the brand appearance settings — (~276 tok)
- `contextualNav.js` — Contextual topbar tabs, keyed by the current URL's prefix. Replaces the old (~2260 tok)
- `formatDate.js` — "2026-08-30T14:05:00Z" -> "30/08/2026 14:05" (~314 tok)
- `formatDuration.js` — Formats a duration in seconds as a short human string — "45s", "3m 20s", "1h 12m". (~173 tok)
- `roleShortcuts.js` — Curates the compact icon rail's contents per role, on top of the existing (~1256 tok)

## resources/views/

- `app.blade.php` — Blade template (~309 tok)

## resources/views/components/


## resources/views/documents/

- `bon-de-sortie.blade.php` — Blade template (~2277 tok)
- `carrier-label.blade.php` — Blade template (~918 tok)
- `internal-voucher.blade.php` — Blade template (~1482 tok)
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

- `api.php` (~396 tok)
- `auth.php` (~1236 tok)
- `console.php` (~391 tok)
- `dashboard.php` (~13135 tok)
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

- `DeliveryBoardDispatchModalTest.php` — Declares dbdmDispatchSource (~1239 tok)
- `DeliveryBoardOzonShipmentTest.php` — boardDispatcher: boardOrder (~1724 tok)
- `DeliveryBoardSenditActionsTest.php` — Declares boardWorkspace (~1858 tok)
- `DeliveryCityMappingResolverTest.php` — Declares makeUnroutedOrder (~2164 tok)
- `DeliveryProviderCityUiPropsTest.php` (~2362 tok)
- `DeliveryProviderFoundationTest.php` (~809 tok)
- `DeliveryProvidersIntegrationTabTest.php` — Declares dpOwnerWorkspace (~1894 tok)
- `DispatchModalProviderModeTest.php` — dmpWorkspace: dmpReadyOrder (~2725 tok)
- `FulfillmentDocumentTest.php` — fulfilDocWorkspace: fulfilDocMemberWithRole, fulfilDocStoredDocument (~1564 tok)
- `InternalAgentDispatchTest.php` — Declares iadWorkspace (~1214 tok)
- `ManualCourierDispatchTest.php` — Declares mcdWorkspace (~1516 tok)
- `OzonBonDeLivraisonLabelsTest.php` — Declares bllFakeOzon (~2650 tok)
- `OzonCityMappingBulkTest.php` — Declares bulkTestManager (~1848 tok)
- `OzonCityMappingSuggestionTest.php` — suggestionFor: ozonCity (~2102 tok)
- `OzonCityMappingTest.php` (~1232 tok)
- `OzonCitySyncTest.php` — Declares ozonManager (~3148 tok)
- `OzonConnectionStatusTest.php` — Declares statusTestManager (~2198 tok)
- `OzonConnectionTest.php` — Declares makeManager (~1704 tok)
- `OzonCreateShipmentResponseParsingTest.php` (~1966 tok)
- `OzonCreateShipmentTest.php` (~2245 tok)
- `OzonDeliveryNoteTest.php` (~1328 tok)
- `OzonDispatchModalTest.php` — Declares odmWorkspace (~1580 tok)
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
- `SenditCityMappingTest.php` (~2488 tok)
- `SenditConnectionTest.php` — Declares senditManager (~1976 tok)
- `SenditCreateShipmentTest.php` — Declares senditLoginResponse (~2642 tok)
- `SenditDispatchModalTest.php` — Declares sdmWorkspace (~1653 tok)
- `SenditDistrictPaginationTest.php` — A Laravel-style flat paginator page: pagination meta alongside a flat "data" row list. (~2411 tok)
- `SenditDistrictSyncTest.php` — A single-page (no pagination metadata at all) districts response — the simplest, still-valid shape. (~2110 tok)
- `SenditLabelsTest.php` (~1094 tok)
- `SenditTrackingTest.php` (~1815 tok)
- `SenditWebhookTest.php` — senditWebhookSign: senditWebhookPayload (~2683 tok)

## tests/Feature/Finance/

- `DeliveryProviderFeeTest.php` — dpfWorkspace: dpfDeliveredShipment, dpfStaffWithPermissions, dpfProviderCity (~6061 tok)
- `DispatchFeeSnapshotTest.php` — Declares dfsSendOzonShipment (~1556 tok)
- `FinanceAccessTest.php` — financeAccessWorkspace: financeAddStaffWithRole (~1091 tok)
- `FinanceAccountTest.php` — Declares financeAccountWorkspace (~1669 tok)
- `FinanceCashflowTest.php` — cashflowWorkspace: cashflowProduct, cashflowPosSession (~6200 tok)
- `FinanceCodReceivableTest.php` — Declares codWorkspace (~10654 tok)
- `FinanceCodSettlementReconciliationTest.php` — fcsrWorkspace: fcsrDeliveredOrder (~3865 tok)
- `FinanceCodSettlementTest.php` — settlementWorkspace: settlementPendingOrder (~2644 tok)
- `FinanceCodSettlementViewPeriodTest.php` — "View settlement period should open the instant period directly" — the (~5802 tok)
- `FinanceCourierDepositTest.php` — depositWorkspace: depositPendingOrder (~2520 tok)
- `FinanceDocumentTest.php` — fdWorkspace: fdExpense, fdStaffWithPermissions (~3537 tok)
- `FinanceExpenseCategoryTest.php` — Declares fecWorkspace (~1141 tok)
- `FinanceExpenseJustificationTest.php` — Internal justification / owner-review workflow for expenses paid without (~5815 tok)
- `FinanceExpenseTest.php` — Declares feWorkspace (~1944 tok)
- `FinanceMonthlyStatementCashflowTest.php` — Declares statementCashflowWorkspace (~3931 tok)
- `FinanceMonthlyStatementTest.php` — Declares fmsWorkspace (~1357 tok)
- `FinanceNavigationTest.php` — Regression coverage for the "Finance is not visible in the main sidebar" (~1736 tok)
- `FinancePayoutFrequencyTest.php` — fpfWorkspace: fpfSettings, fpfDeliveredOrder (~3939 tok)
- `FinancePayrollTest.php` — fpyWorkspace: fpyEmployee (~2863 tok)
- `FinanceRecurringExpenseTest.php` — Declares freWorkspace (~1745 tok)
- `FinanceTransactionTest.php` — Declares financeTxWorkspace (~1399 tok)
- `FinanceVendorTest.php` — Declares fvWorkspace (~774 tok)

## tests/Feature/Foundation/

- `AdminOperationsNavigationClarityTest.php` — Admin Operations Navigation Clarity — an admin (privileged store owner) (~1891 tok)
- `AgencyNavigationSeparationTest.php` — Declares agencyNavWorkspace (~767 tok)
- `AgencyOperationsNavigationTest.php` — Agency Operations Navigation — an agency admin operating a shared (~1926 tok)
- `AgencyOrderSourceScopeTest.php` — Phase OST6 — an agency admin filtering by source (platform/connection) (~1809 tok)
- `AgentActivityEventTest.php` — The agent_activity_events ledger is written additively, after a workflow (~1727 tok)
- `AgentDashboardMetricsTest.php` — Every number AgentDashboardMetricsService reports is derived straight from (~1587 tok)
- `AgentPointsPreviewTest.php` — "Performance points preview" — a foundation-only, read-only projection over (~1106 tok)
- `AppearanceSettingsTest.php` — apstWorkspace: apstCashier, apstPageSource (~826 tok)
- `AppShellNavigationTest.php` — asntWorkspace: asntRailSource, asntDrawerSource, asntTopbarSource, asntSaasLayoutSource (~1026 tok)
- `BrandAppearancePersistenceTest.php` — Declares bapWorkspace (~1284 tok)
- `BrandedErrorPageTest.php` — A full-page GET request that hits 403/404/419/500 renders the branded (~830 tok)
- `ChannelFrontendCoverageTest.php` — Declares channelCoverageWorkspace (~1285 tok)
- `CityWarehouseAllocationShortageTest.php` — City-to-warehouse allocation, including the "no mapping found" fallback (~2635 tok)
- `ComponentThemeConsistencyTest.php` — Declares ctcRead (~567 tok)
- `ConfirmationAddressPrefillTest.php` — Confirmation Desk address prefill — the customer's original (~2861 tok)
- `ConfirmationAgentDashboardTest.php` — cadWorkspace: cadAgent (~703 tok)
- `ConfirmationCityWarehouseSelectionTest.php` — Confirmation Desk city selection — the city dropdown is preselected from (~2636 tok)
- `ConfirmationDeskClaimTest.php` — Confirmation Desk claim-gated actions — an order must be claimed by the (~3527 tok)
- `ConfirmationOrderCardActionTest.php` — The Orders board / Confirmation Desk cards must never show an enabled (~2142 tok)
- `ConnectionAuthClarityTest.php` — cacWorkspace: cacWoo (~2054 tok)
- `ConnectionOrderSyncBatchTest.php` — cosbWorkspace: cosbWoo (~1775 tok)
- `ConnectionProductArchiveTest.php` — cpaWorkspace: cpaWoo, cpaShopify, cpaProduct (~1526 tok)
- `ConnectionProfileTest.php` — cpWorkspace: cpWoo, cpShopifyClientCredentials (~1704 tok)
- `ConnectionScopeTest.php` — csWorkspace: csWoo (~1431 tok)
- `ConnectionSyncResetTest.php` — csrWorkspace: csrWoo, csrShopify (~2982 tok)
- `ContextualTopbarTest.php` — ctntWorkspace: ctntConfigSource, ctntHrefs (~633 tok)
- `DashboardNavigationVisibilityTest.php` — navWorkspace: navMemberWithRole (~1588 tok)
- `DeliveryAgentDashboardTest.php` — dadWorkspace: dadDispatcher (~854 tok)
- `ExternalStockPushJobTest.php` — Phase S6 — ExternalStockPushJob is the optional async wrapper around (~2166 tok)
- `FulfillmentAgentDashboardTest.php` — fadWorkspace: fadAgent (~879 tok)
- `InertiaForbiddenActionTest.php` — App\Support\InertiaErrorResponder: a genuine Inertia SPA action (the (~1443 tok)
- `IntegrationNavigationTest.php` — inOwnerWorkspace: inManager, inSidebarSource, isNavItemActive (~1734 tok)
- `IntegrationsCenterTest.php` — Declares icOwnerWorkspace (~1336 tok)
- `IntegrationsTabsTest.php` — itOwnerWorkspace: itManager, itViewer (~986 tok)
- `InventoryEngineTest.php` — inventoryMerchant: inventoryProduct (~4304 tok)
- `NavigationRefinementTest.php` — nrtWorkspace: nrtRoleShortcutsSource, nrtRailSource, nrtDrawerSource + 3 more (~2394 tok)
- `NewOrderNotificationTest.php` — nonWorkspace: nonMember, nonShopifyWebhook (~1854 tok)
- `OnlineOrderLineInventoryResolverTest.php` — OrderLineInventoryResolver — the single, platform-agnostic resolver for (~2807 tok)
- `OnlineOrderReservationPolicyTest.php` — Phase O2 — online order reservation policy. Default: a pending (~2205 tok)
- `OperationsNavigationTest.php` — opsNavWorkspace: opsNavMember (~1082 tok)
- `OrderActionAuthorizationUxTest.php` — Backend authorization for order actions stays strict no matter what the (~1426 tok)
- `OrderExternalStockSyncTest.php` — Phase O6 — every order/POS/return event that changes SELLABLE available (~2380 tok)
- `OrderInventoryConsistencyTest.php` — Phase O1/O8 — end-to-end online-order inventory lifecycle consistency: (~2220 tok)
- `OrderLineInventoryMappingTest.php` — Phase O4 — order line inventory resolution: ProductVariantChannelListing (~2411 tok)
- `OrderNotificationBadgeTest.php` — onbWorkspace: onbMember, onbShopifyWebhook (~2086 tok)
- `OrderSourceFilteringTest.php` — Phase OST5/OST6 — the Orders index filters by source_type (pos/online), (~1970 tok)
- `OrderSourceTrackingTest.php` — Phase OST2/OST4 — every order carries normalized, queryable source (~2466 tok)
- `OrderSourceUiPropsTest.php` — Phase OST5 — the Confirmation Desk queue and the order detail page both (~1394 tok)
- `OrdersPageDeDuplicationTest.php` — opdWorkspace: opdManageSource (~1030 tok)
- `OrderSyncIncrementalTest.php` — WooCommerceConnector::getOrders() sends `after` as a GET query param, not a form/JSON body — Http::R (~2197 tok)
- `OrderSyncQueueTest.php` — osqWorkspace: osqWooConnection (~1662 tok)
- `OrderWebhookIdempotencyTest.php` — Declares owiWorkspace (~1517 tok)
- `OwnerDashboardMetricsTest.php` — The owner dashboard must show a WHOLE-BUSINESS view — POS + online (~2299 tok)
- `PermissionAwareAppearanceTest.php` — paaOwnerWorkspace: paaManager (~789 tok)
- `PermissionAwareNavigationTest.php` — pantOwnerWorkspace: pantMember, pantRoleShortcutsSource (~1274 tok)
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
- `RoleBasedDashboardTest.php` — /dashboard renders a different page per role — see (~1089 tok)
- `SettingsPageMigrationTest.php` (~381 tok)
- `ShopifyAutomaticOrderImportTest.php` — The full acceptance scenario from the brief: a new Shopify order arrives (~1856 tok)
- `ShopifyCanonicalPublishMapperTest.php` — shopifyMapperWorkspace: shopifyMapperProduct (~2150 tok)
- `ShopifyCapabilityDiagnosticsTest.php` — cdWorkspace: cdConnection, cdTokenFake (~2992 tok)
- `ShopifyClientCredentialsAuthTest.php` — sccWorkspace: sccConnection, sccFakeTokenResponse (~2800 tok)
- `ShopifyConnectionAuthStatusTest.php` — Root cause: ShopifyAuthService::testConnection() hard-gated on the (~2233 tok)
- `ShopifyConnectionWorkflowTest.php` — Declares scwWorkspace (~1993 tok)
- `ShopifyInventorySyncTest.php` — Shopify quantity is never set via a product/variant update payload — it (~3870 tok)
- `ShopifyManualSyncStillWorksTest.php` — Manual sync ("Sync" button on the Shopify connection profile) must keep (~1092 tok)
- `ShopifyOrderImportIdempotencyTest.php` — Shopify orders are unique by (platform_connection_id, platform_order_id) — (~1555 tok)
- `ShopifyOrderLineInventoryMappingTest.php` — Shopify order line -> local product/variant/InventoryItem mapping, via the (~2493 tok)
- `ShopifyOrderWebhookImportTest.php` — sowiWorkspace: sowiHeaders (~1927 tok)
- `ShopifyPublishMirrorsSaasProductTest.php` — The SaaS Product is the source of truth: publishing must mirror its (~2184 tok)
- `ShopifyPublishMirrorsSaasProductTest.php` — The SaaS Product is the source of truth: publishing must mirror its (~2161 tok)
- `ShopifyScheduledOrderImportTest.php` — routes/console.php's every-minute Schedule::call() is already (~1291 tok)
- `ShopifySimpleDefaultVariantStrategyTest.php` — Phase S4 — consolidating regression test for the simple/variable Shopify (~2593 tok)
- `ShopifySimpleProductReadinessTest.php` — A product previously tested as variable in SaaS/Shopify, whose (~2596 tok)
- `ShopifySimpleSkuPublishTest.php` — Shopify SKU belongs to the variant, never the product parent — even a (~3015 tok)
- `ShopifySimpleToVariablePublishTest.php` — Covers the core bug: a Shopify-imported simple product is converted to (~3237 tok)
- `ShopifySimpleToVariablePublishTest.php` — Covers the core bug: a Shopify-imported simple product is converted to (~2877 tok)
- `ShopifyStockAdjustmentPushTest.php` — POST /dashboard/products/{product}/stock is the inventory-safe adjustment (~3193 tok)
- `ShopifyVariantInventorySyncTest.php` — A Shopify variant's stock lives on InventoryLevel (inventory_item_id + (~2927 tok)
- `ShopifyVariantSkuPublishTest.php` — For a variable product, SKU lives on each Shopify variant — publishing (~1461 tok)
- `ShopifyWebhookConnectionResolutionTest.php` — The webhook URL is per-connection (/api/webhooks/shopify/{connection}), (~1351 tok)
- `ShopifyWebhookOrderImportTest.php` — ShopifyWebhookController must return quickly: verify HMAC → resolve (~2037 tok)
- `ShopifyWebhookSignatureTest.php` — The root-cause fix: a Shopify connection using admin_client_credentials (~1582 tok)
- `ShopifyWebhookTest.php` — shopifyWebhookWorkspace: shopifyWebhookHeaders, shopifyProductPayload, shopifyOrderPayload (~2169 tok)
- `SidebarHoverClickSeparationTest.php` — shcsWorkspace: shcsRailSource, shcsTriggerSource, shcsLayoutSource, shcsRoleShortcutsSource (~1369 tok)
- `SidebarNavigationPolishTest.php` — snptWorkspace: snptRailSource, snptDrawerSource, snptSaasLayoutSource, snptRoleShortcutsSource, snpt (~1758 tok)
- `StoreCreationFoundationTest.php` — A real Store + membership under $organization, so the owner can actually (~1977 tok)
- `SupervisorDashboardMetricsTest.php` — supervisorDashboardMetricsWorkspace: supervisorDashboardMetricsMember (~1996 tok)
- `ThemeModeTest.php` — themeAppCss: themeShellSource, themeTopbarSource (~718 tok)
- `ThemeTokenTest.php` — Declares ttAppCss (~590 tok)
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

## tests/Feature/Payroll/

- `EmployeeTest.php` — Employees are a PAYROLL concept, deliberately separate from (~1375 tok)
- `PayrollTest.php` — pyrWorkspace: pyrEmployee (~2306 tok)

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

