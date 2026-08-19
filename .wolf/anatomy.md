# anatomy.md

> Auto-maintained by OpenWolf. Last scanned: 2026-08-19T14:54:06.677Z
> Files: 132 tracked | Anatomy hits: 0 | Misses: 0

## ../../../../../../laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/


## ../../../../.claude/jobs/a866b444/tmp/


## ../../../../.claude/plans/

- `tidy-frolicking-moler.md` — Option B2 — Migrate Product Create/Edit wizard to React/Inertia (~3185 tok)

## ../../../../AppData/Local/Temp/


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


## app/Console/Commands/


## app/Contracts/


## app/Enums/


## app/Events/


## app/Exceptions/


## app/Factories/


## app/Http/Controllers/


## app/Http/Controllers/Admin/


## app/Http/Controllers/Api/


## app/Http/Controllers/Auth/


## app/Http/Controllers/Dashboard/

- `DashboardController.php` — index (~1906 tok)
- `OperationsController.php` — Focused, single-station queues layered over the existing department (~837 tok)
- `OrderController.php` — Unified orders list — POS and online in one filterable, paginated table. (~3362 tok)
- `ProductController.php` — index, syncFromPlatform, create, store, edit + 1 more (~5150 tok)
- `StockController.php` — id => name for the active store's sellable warehouses (set per request). (~5223 tok)
- `StockTransferController.php` — index, create, store, slip (~2839 tok)
- `StoreController.php` — Add Store is Organization-first: it never invents a workspace. It shows (~2348 tok)
- `WarehouseController.php` — index, create, store, edit, update (~1879 tok)

## app/Http/Controllers/Onboarding/

- `AgencyOnboardingController.php` — show, storeOrganization, storeServices, storeWarehouses, storeClient + 4 more (~2385 tok)
- `MerchantOnboardingController.php` — show, storeOrganization, storeStore, storeWarehouses, storeSetup + 1 more (~1564 tok)
- `OnboardingController.php` — The literal first onboarding question: "How will you use the (~2042 tok)

## app/Http/Controllers/Pos/

- `CheckoutController.php` — store (~1233 tok)
- `PosController.php` — Eager loads needed to present a product with its variants in one query set: (~1924 tok)

## app/Http/Controllers/Settings/

- `SettingsController.php` — Account-level settings (Profile/Appearance/Security) — replaces the (~1116 tok)

## app/Http/Middleware/

- `HandleInertiaRequests.php` — HandleInertiaRequests: version, share (~933 tok)

## app/Http/Requests/Auth/


## app/Jobs/


## app/Jobs/Pos/


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

- `PosOrderItem.php` — Model — 13 fields, 3 rels (~369 tok)
- `StockLedger.php` — Model — table: stock_ledger, 12 fields, 5 rels (~368 tok)
- `StockTransfer.php` — A Stock Transfer / Bon de Sortie (exit slip): the authoritative record of goods (~816 tok)
- `StockTransferItem.php` — Model — 8 fields, 3 rels (~270 tok)
- `Store.php` — Model — 18 fields, 16 rels (~3193 tok)

## app/Models/Concerns/


## app/Models/Scopes/


## app/Notifications/


## app/Policies/


## app/Providers/

- `FortifyServiceProvider.php` — Register any application services. (~839 tok)

## app/Repositories/


## app/Services/


## app/Services/Agency/

- `AgencyWorkspaceService.php` — AgencyWorkspaceService: createClient, createAgencyWarehouse, assignWarehouse, assignService (~1276 tok)

## app/Services/Invoicing/


## app/Services/Meta/


## app/Services/Onboarding/

- `AgencyOnboardingService.php` — Backs the agency onboarding wizard (Step 8 / A1-A10). Reuses (~1765 tok)
- `MerchantOnboardingService.php` — Backs the merchant onboarding wizard (Step 8 / M1-M6). Each method is one (~1964 tok)

## app/Services/Orders/

- `OperationsQueueService.php` — Cross-store operational queues, scoped by warehouse OPERATOR rather than the (~2334 tok)

## app/Services/Pos/

- `DocumentGenerationService.php` — Render a finalized Facture to an A4 PDF and persist it. Returns the (~2978 tok)
- `OrderProcessingService.php` — Create a POS order with its line items. Runs in a single transaction so (~2940 tok)

## app/Services/Stocks/

- `StockTransferService.php` — Record a stock transfer / Bon de Sortie and move the goods atomically. (~2656 tok)

## app/Services/Sync/


## app/Services/WhatsApp/


## app/Support/

- `OnboardingOptions.php` — Static option lists shared by every onboarding controller/page — kept in (~1309 tok)
- `OrderPresenter.php` — Normalizes POS and online orders into one shape for the Order Management view, (~1996 tok)
- `PermissionCatalog.php` — Central catalogue of every granular permission a store role can grant. (~2960 tok)

## app/View/Components/


## bootstrap/


## bootstrap/cache/


## config/


## database/


## database/factories/


## database/migrations/

- `2026_07_26_000001_add_variant_id_to_pos_order_items_table.php` — Migration: alter pos_order_items table (~294 tok)
- `2026_07_26_000002_add_variant_id_to_stock_ledger_table.php` — Migration: alter stock_ledger table (~276 tok)
- `2026_07_27_000001_create_stock_transfers_table.php` — Migration: create stock_transfers table (~719 tok)
- `2026_07_27_000002_create_stock_transfer_items_table.php` — Migration: create stock_transfer_items table (~391 tok)

## database/seeders/


## docs/


## public/


## resources/css/


## resources/js/

- `app.jsx` — /*.jsx', { eager: true }); (~174 tok)

## resources/js/Components/

- `Button.jsx` — Codifies the button classes already used consistently across (~340 tok)
- `Card.jsx` — Thin wrapper for the `bg-surface-2 border border-line rounded-xl` pattern (~396 tok)
- `StatusBadge.jsx` — Tinted status chips. Background tint works in both modes; text darkens in (~840 tok)
- `StoreSwitcher.jsx` — StoreSwitcher (~1755 tok)
- `TypeBadge.jsx` — Same tinted-pill language as StatusBadge, for the two "what kind of thing (~353 tok)

## resources/js/Components/Dashboard/

- `AdjustStockModal.jsx` — Each tab is a distinct inventory workflow. `mode` maps to the backend contract (~6108 tok)

## resources/js/Components/Departments/

- `OperationsFilterBar.jsx` — Warehouse / city / assignee / client-org select filters for an operations queue. (~377 tok)
- `OperationsNav.jsx` — Switcher across the five single-station operations queues. (~955 tok)
- `OperationsTable.jsx` — Shared table body for the four order-based operations queues (~1264 tok)

## resources/js/Components/Filters/


## resources/js/Components/Onboarding/

- `Field.jsx` — Extracted from the original onboarding Wizard so every onboarding page shares one input style. (~360 tok)
- `OnboardingShell.jsx` — Shared page chrome for every onboarding screen — header, step circles, (~1141 tok)
- `Select.jsx` — Extracted from the original onboarding Wizard so every onboarding page shares one input style. (~404 tok)
- `WizardFooter.jsx` — Back / Skip / Continue row shared by every onboarding step. (~552 tok)

## resources/js/Components/Settings/

- `SettingsNav.jsx` — Same "switcher across N related pages" pattern as DepartmentNav/OperationsNav. (~462 tok)

## resources/js/Hooks/

- `useCart.js` — initialState: reducer, clampPercent, lineSubtotal + 5 more (~2931 tok)
- `useOperationsFilters.js` — Warehouse / city / assigned-employee / client-organization filters layered (~432 tok)
- `useQueue.js` — Shared state for a department work queue. (~1013 tok)

## resources/js/Layouts/

- `AgencyLayout.jsx` — Lightweight shell for the agency workspace — deliberately not a second (~1087 tok)
- `AuthLayout.jsx` — Shared shell for the secondary auth screens (verify email, two-factor (~436 tok)
- `SaasLayout.jsx` — NAV_SECTIONS (~4582 tok)

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
- `Stock.jsx` — Stock (~6364 tok)
- `StockMovements.jsx` — TYPE_STYLES — renders table (~1675 tok)
- `StockTransferCreate.jsx` — KINDS — renders form (~7323 tok)
- `StockTransfers.jsx` — KIND_BADGE — renders table (~2880 tok)

## resources/js/Pages/Dashboard/Delivery/


## resources/js/Pages/Dashboard/Departments/

- `Confirmation.jsx` — Confirmation desk — the 'Pending confirmation' queue. (~4769 tok)
- `Packing.jsx` — Pick & pack bench — confirmed online orders and delivery-bound POS orders in (~4717 tok)

## resources/js/Pages/Dashboard/Integrations/

- `Index.jsx` — ICONS (~1603 tok)

## resources/js/Pages/Dashboard/Integrations/Platforms/


## resources/js/Pages/Dashboard/Operations/

- `Packing.jsx` — Picked orders being boxed up for handover — status = packing only. (~1150 tok)
- `Picking.jsx` — Orders ready to pick, plus those currently being picked. (~1712 tok)
- `ReadyForDelivery.jsx` — Packed orders staged for handover. Carrier assignment stays on the existing (~1296 tok)
- `TransferReceiving.jsx` — Inbound InventoryTransfer rows awaiting receipt at a warehouse this org runs. (~1642 tok)
- `WaitingForStock.jsx` — Read-only monitoring queue: confirmed orders held back because a transfer is (~855 tok)

## resources/js/Pages/Dashboard/Orders/

- `Index.jsx` — STATUS_OPTIONS — renders table (~2226 tok)
- `Index.jsx` — Unified POS+online orders list; Source/Status filters, origin badges, view/receipt actions (~1600 tok)
- `Manage.jsx` — COLUMNS (~12464 tok)
- `Manage.jsx` — Multi-channel fulfillment board (Kanban+table); dept/source tabs, drawer transitions (~7000 tok)
- `Show.jsx` — POS order detail (invoice + thermal receipt) (~1900 tok)
- `ShowOnline.jsx` — ShowOnline — renders table (~3073 tok)
- `ShowOnline.jsx` — Online order detail: generate/view A4 invoice + print thermal receipt (~1700 tok)

## resources/js/Pages/Dashboard/Orders/Returns/


## resources/js/Pages/Dashboard/Products/

- `Edit.jsx` — Edit (~10499 tok)
- `Index.jsx` — Index — renders table (~2131 tok)

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

- `auth.php` (~1236 tok)
- `dashboard.php` (~4484 tok)
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


## tests/Feature/

- `_SmokeCheck.php` (~546 tok)

## tests/Feature/Api/


## tests/Feature/Auth/

- `AuthenticationTest.php` (~406 tok)
- `EmailVerificationTest.php` (~376 tok)
- `PasswordConfirmationTest.php` (~248 tok)
- `PasswordResetTest.php` (~500 tok)
- `RegistrationTest.php` (~164 tok)

## tests/Feature/Foundation/

- `ProductWizardMigrationTest.php` — Declares pwmWorkspace (~2277 tok)
- `SettingsPageMigrationTest.php` (~381 tok)
- `StoreCreationFoundationTest.php` — A real Store + membership under $organization, so the owner can actually (~1977 tok)

## tests/Feature/Invoicing/


## tests/Feature/Onboarding/

- `AgencyOnboardingTest.php` — Declares completeAgencyOrganizationStep (~1641 tok)
- `MerchantOnboardingTest.php` — completeMerchantOrganizationStep: completeMerchantStoreStep (~1199 tok)
- `OnboardingFlowTest.php` (~631 tok)

## tests/Feature/Orders/

- `OperationalQueueTest.php` — Same merchant/product helpers as FulfillmentWorkflowTest — one org, one (~5167 tok)
- `OrderChannelViewsTest.php` (~1510 tok)
- `PosDeliveryRoutingTest.php` (~1315 tok)

## tests/Feature/Roles/


## tests/Feature/Settings/

- `ProfileUpdateTest.php` (~483 tok)
- `SecurityTest.php` (~823 tok)

## tests/Feature/Stocks/

- `StockDashboardTest.php` (~1433 tok)
- `StockTransferTest.php` — Declares transferData (~2029 tok)

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

