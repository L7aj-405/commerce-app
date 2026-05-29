# anatomy.md

> Auto-maintained by OpenWolf. Last scanned: 2026-05-27T22:10:43.350Z
> Files: 131 tracked | Anatomy hits: 0 | Misses: 0

## ../../../../../../laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/

- `php.ini` (~62 tok)

## ../../../../AppData/Local/Temp/

- `drop_pvav.php` (~92 tok)

## ./

- `.npmrc` (~6 tok)
- `check_enum_temp.php` (~212 tok)
- `drop_pvav.php` (~74 tok)
- `phpunit.xml` (~378 tok)
- `pint.json` (~8 tok)
- `tailwind.config.js` (~161 tok)

## .claude/

- `settings.json` (~441 tok)
- `settings.local.json` (~547 tok)

## .claude/rules/

- `openwolf.md` (~313 tok)

## .github/workflows/


## app/


## app/Actions/Fortify/


## app/Concerns/


## app/Connectors/

- `ShopifyConnector.php` — Shopify Admin REST API connector (version 2024-01). (~8268 tok)
- `WooCommerceConnector.php` — Parse WooCommerce product to normalized format (~7716 tok)
- `YouCanConnector.php` — YouCan Shop REST API connector. (~6257 tok)

## app/Console/Commands/


## app/Contracts/


## app/Enums/


## app/Events/


## app/Exceptions/


## app/Factories/


## app/Http/Controllers/


## app/Http/Controllers/Api/


## app/Http/Controllers/Auth/

- `MetaOAuthController.php` — handleMetaCallback, showAccountSelector, handleAccountSelection, showNumberSelector, handleNumberSel (~1804 tok)

## app/Http/Middleware/


## app/Http/Requests/Auth/


## app/Jobs/

- `SendWhatsAppConfirmation.php` — SendWhatsAppConfirmation: handle, failed (~290 tok)

## app/Livewire/


## app/Livewire/Actions/


## app/Livewire/Forms/


## app/Livewire/Orders/


## app/Livewire/Products/

- `ProductCreationWizard.php` — ProductCreationWizard: mount, nextStep, prevStep, goToStep + 14 more (~5629 tok)
- `ProductEditWizard.php` — ProductEditWizard: mount, changeProductType, cancelTypeChange, confirmTypeChange + 4 more (~6668 tok)

## app/Livewire/Profile/


## app/Livewire/Stores/

- `WhatsAppSetupWizard.php` — WhatsAppSetupWizard: mount, selectMethod, nextStep, prevStep + 1 more (~1003 tok)
- `WhatsAppUserSetup.php` — WhatsAppUserSetup: mount, validateToken, selectAccount, selectPhone + 1 more (~1075 tok)

## app/Livewire/Stores/Connections/


## app/Livewire/Stores/Settings/

- `WhatsappSettings.php` — WhatsappSettings: mount, save, testConnection, stats + 2 more (~1003 tok)
- `WhatsAppTemplates.php` — WhatsAppTemplates: mount, select, save, preview + 2 more (~486 tok)

## app/Models/


## app/Notifications/


## app/Policies/


## app/Providers/


## app/Services/

- `WhatsAppWebhookHandler.php` — Extract phone, body, and timestamp from Meta's nested webhook payload. (~1436 tok)

## app/Services/Connectors/


## app/Services/Meta/

- `MetaMessageService.php` — Send a WhatsApp interactive message with quick-reply buttons. (~1218 tok)

## app/Services/Stocks/


## app/Services/Sync/

- `OrderSyncService.php` — Page through all orders on the platform and persist them to the store. (~1397 tok)
- `ProductPushService.php` — Create a brand-new product on every connected platform and save the returned (~5690 tok)

## app/Services/WhatsApp/

- `MessageTemplates.php` — MessageTemplates: all, get, keys, render, renderPreview (~1030 tok)
- `WhatsAppMessageService.php` — WhatsAppMessageService: sendOrderConfirmation (~597 tok)

## app/View/Components/


## bootstrap/

- `app.php` (~185 tok)
- `providers.php` (~44 tok)

## bootstrap/cache/

- `packages.php` (~500 tok)
- `services.php` (~6191 tok)

## config/

- `app.php` (~1140 tok)
- `auth.php` (~1078 tok)
- `cache.php` (~1108 tok)
- `database.php` (~1862 tok)
- `filesystems.php` (~676 tok)
- `fortify.php` (~1426 tok)
- `logging.php` (~1158 tok)
- `meta.php` (~54 tok)
- `queue.php` (~1120 tok)
- `session.php` (~2247 tok)
- `whatsapp.php` (~167 tok)

## database/


## database/factories/


## database/migrations/

- `2026_05_25_300000_make_product_attributes_per_product.php` — Migration: change product_attributes.store_id -> product_id (per-product scoping) (~195 tok)
- `2026_05_26_000001_scope_product_variant_sku_unique_to_product.php` — Migration: alter product_variants table (~195 tok)

## database/seeders/


## public/

- `hot` (~9 tok)
- `index.php` (~145 tok)
- `robots.txt` (~6 tok)

## resources/css/

- `app.css` — Styles: 5 rules (~1699 tok)

## resources/js/

- `app.js` (~0 tok)

## resources/views/


## resources/views/components/


## resources/views/flux/icon/


## resources/views/flux/navlist/


## resources/views/layouts/

- `app.blade.php` — Blade template (~515 tok)

## resources/views/layouts/app/

- `header.blade.php` — Blade template (~2088 tok)
- `sidebar.blade.php` — Blade template (~3971 tok)

## resources/views/layouts/auth/


## resources/views/livewire/

- `.gitkeep` (~0 tok)
- `dashboard.blade.php` — Blade template (~2612 tok)

## resources/views/livewire/layout/


## resources/views/livewire/orders/

- `order-details.blade.php` — Blade template (~3564 tok)
- `order-index.blade.php` — Blade template (~2331 tok)

## resources/views/livewire/pages/auth/


## resources/views/livewire/products/

- `product-creation-wizard.blade.php` — Blade template (~15527 tok)
- `product-edit-wizard.blade.php` — Blade template (~19335 tok)
- `product-edit.blade.php` — Blade template (~6264 tok)
- `product-index.blade.php` — Blade template (~2884 tok)
- `product-stock.blade.php` — Blade template (~2759 tok)
- `product-variants.blade.php` — Blade template (~4399 tok)

## resources/views/livewire/stores/

- `create-store.blade.php` — Blade template (~1852 tok)
- `edit-store.blade.php` — Blade template (~1994 tok)
- `settings-layout.blade.php` — Blade template (~942 tok)
- `store-index.blade.php` — Blade template (~2904 tok)
- `whatsapp-setup-wizard.blade.php` — Blade template (~9727 tok)
- `whatsapp-user-setup.blade.php` — Blade template (~5472 tok)

## resources/views/livewire/stores/settings/

- `whatsapp-settings.blade.php` — Blade template (~4068 tok)
- `whatsapp-templates.blade.php` — Blade template (~1966 tok)

## resources/views/livewire/welcome/


## resources/views/meta/

- `account-selector.blade.php` — Blade template (~1439 tok)
- `number-selector.blade.php` — Blade template (~1853 tok)

## resources/views/pages/auth/


## resources/views/pages/settings/


## resources/views/pages/settings/two-factor/


## resources/views/partials/


## routes/

- `api.php` (~171 tok)
- `auth.php` (~203 tok)
- `console.php` (~56 tok)
- `settings.php` (~239 tok)
- `web.php` (~1525 tok)

## storage/app/


## storage/app/private/


## storage/app/public/


## storage/framework/


## storage/framework/cache/


## storage/framework/cache/data/


## storage/framework/sessions/


## storage/framework/testing/


## storage/framework/views/

- `0e0711aab3effe5aa15752ebd07c5fcf.php` (~3880 tok)
- `172cdc0be751d37c3834dc6000ab6eb1.php` (~3917 tok)
- `2b2e634cdd48e2dca60b191f4e59cbce.php` (~5564 tok)
- `2cf985fd10086b0a9580592b10a23670.php` (~5914 tok)
- `318410bb52da036d44fbe2338860a157.php` (~5312 tok)
- `3383041526ead821defdd3e1ef2aff03.php` (~5967 tok)
- `41e2c9fce789d19c110da358c629db3e.php` (~4622 tok)
- `42477f8093ba3c62c72eccba81921e9a.php` (~4396 tok)
- `4853a5de36a82f48a96458df9f96d472.php` (~3459 tok)
- `59a19dabd9095c16d89d55715a07bae7.php` (~12463 tok)
- `63236015cf932338260ec53015f77eab.php` (~4226 tok)
- `6c2bb0d951bd1acccc73fc67a688fc20.php` (~4583 tok)
- `78ea6f9eedffcddacac0f089633701dc.php` (~3917 tok)
- `989072af0f76b6982836130cde5ae1f7.php` (~8592 tok)
- `9e3dd3e6b732ee585383922d0bb75175.php` (~11807 tok)
- `b19233f2993944ca104d39efda628436.php` (~5967 tok)
- `b1d909a2b112cae55714f8e2aea3c2a3.php` (~4575 tok)
- `b77428be402b5865bd6ce58659b5338b.php` (~4038 tok)
- `b7d639b75966a3496beaf3610821d13f.php` (~6324 tok)
- `cccde12253f4cf8b46e59be108e15ddd.php` (~4595 tok)
- `d31b2ce44d44cc7de4228190c0e3d058.php` (~7250 tok)
- `d987c91298550c76c5d75c6d3b217bf7.php` (~4315 tok)

## storage/logs/

- `whatsapp-webhooks-2026-05-05.log` (~1652 tok)

## tests/


## tests/Feature/

- `DashboardTest.php` (~108 tok)
- `ExampleTest.php` (~35 tok)
- `ProfileTest.php` (~614 tok)

## tests/Feature/Api/


## tests/Feature/Auth/

- `AuthenticationTest.php` (~425 tok)
- `EmailVerificationTest.php` (~354 tok)
- `PasswordConfirmationTest.php` (~288 tok)
- `PasswordResetTest.php` (~540 tok)
- `PasswordUpdateTest.php` (~299 tok)
- `RegistrationTest.php` (~176 tok)
- `TwoFactorChallengeTest.php` (~198 tok)

## tests/Feature/Orders/

- `OrderManagementTest.php` (~1318 tok)

## tests/Feature/Settings/

- `ProfileUpdateTest.php` (~530 tok)
- `SecurityTest.php` (~817 tok)

## tests/Unit/

- `ExampleTest.php` (~22 tok)

## tests/Unit/Models/


## vendor/

- `pest-plugins.json` (~211 tok)

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

- `carbon.bat` (~36 tok)
- `paratest_for_phpstorm.bat` (~40 tok)
- `paratest.bat` (~36 tok)
- `patch-type-declarations.bat` (~40 tok)
- `pest.bat` (~35 tok)
- `php-parse.bat` (~37 tok)
- `phpunit.bat` (~36 tok)
- `pint.bat` (~35 tok)
- `psysh.bat` (~36 tok)
- `sail.bat` (~41 tok)
- `var-dump-server.bat` (~38 tok)
- `yaml-lint.bat` (~37 tok)

## vendor/brianium/paratest/


## vendor/brianium/paratest/bin/

- `paratest` (~265 tok)
- `paratest_for_phpstorm` (~84 tok)
- `phpunit-wrapper.php` (~724 tok)

## vendor/brianium/paratest/src/


## vendor/brianium/paratest/src/Coverage/


## vendor/brianium/paratest/src/JUnit/

