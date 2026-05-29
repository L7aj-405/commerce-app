# PROJECT REPORT — SaaS Commerce

## PROJECT SUMMARY
A multi-tenant Laravel 13 SaaS platform for merchants to manage stores, sync products and orders from WooCommerce, Shopify, and YouCan, and automate customer confirmations via WhatsApp. Users own one or more stores; each store connects to one or more e-commerce platforms via PlatformConnection, pulls inventory into a warehouse-backed product catalog, and routes order confirmation messages through Meta or Evolution API.

---

## FILE COUNT BY DIRECTORY

| Directory | Count |
|-----------|-------|
| app/Models | 12 |
| app/Livewire | 26 |
| app/Services | 18 |
| app/Connectors (Phase 2) | 4 |
| app/Services/Connectors (Phase 1) | 4 |

---

## MODELS

| Model | Purpose |
|-------|---------|
| User | Auth user with status enum and 2FA |
| Store | Merchant store (online / physical / hybrid) |
| StoreCredential | Encrypted API tokens and WhatsApp config |
| PlatformConnection | WooCommerce / Shopify / YouCan connection |
| Product | Synced product with SKU, price, images |
| ProductVariant | Size/color variants per product |
| Order | Customer order from platform sync |
| Warehouse | Physical storage location |
| Stock | Quantity on hand per product per warehouse |
| StockMovement | Audit log of stock in/out |
| SyncLog | Record of each platform sync run |
| CustomerInteraction | WhatsApp conversation log |

---

## ROUTES

**Public**
- `GET /`

**Auth**
- `GET /login` · `POST /login` · `POST /logout`
- `GET /register` · `POST /register`
- `GET /forgot-password` · `POST /forgot-password`
- `GET /reset-password/{token}`
- `GET /verify-email`
- `GET /auth/meta/callback`

**Authenticated (auth + verified + check.status)**
- `GET /dashboard`
- `GET /profile`
- `GET /orders`
- `GET /orders/{order}`
- `GET /stores`
- `GET /stores/create`
- `GET /stores/{store}/edit`
- `GET /stores/{store}/settings/whatsapp`
- `GET /stores/{store}/connections`
- `GET /stores/{store}/connections/connect`
- `GET /stores/{store}/connections/{connection}/edit`
- `GET /stores/{store}/products`
- `GET /stores/{store}/products/create`
- `GET /stores/{store}/products/{product}/edit`
- `GET /warehouses`
- `GET /warehouses/create`
- `GET /warehouses/{warehouse}/edit`

**API**
- `GET  /api/webhooks/whatsapp` — Meta hub challenge verification
- `POST /api/webhooks/whatsapp` — Incoming WhatsApp messages
- `GET  /api/webhooks/whatsapp/health`

---

## SERVICES

| Service | Role |
|---------|------|
| SyncService | Orchestrator — delegates to Product/OrderSyncService |
| ProductSyncService | Fetch + persist products from platform connectors |
| OrderSyncService | Fetch + persist orders from platform connectors |
| WhatsAppWebhookHandler | Parse Meta webhook payloads, match orders by phone |
| WhatsAppConfirmationService | Send order confirmation messages via Meta or Evolution |
| EvolutionApiService | Evolution WhatsApp API client |
| AIResponseAnalyzerService | Classify customer replies with AI |
| ActionRouterService | Route AI analysis result to confirm/cancel/escalate |
| MetaMessageService | Send messages via Meta Cloud API |
| MetaOAuthService | Meta OAuth login URL generation |
| ProductService | Product CRUD business logic |
| WarehouseService | Warehouse CRUD business logic |
| StockService | Stock quantity management |

---

## CONNECTORS

| Connector | Namespace | Methods |
|-----------|-----------|---------|
| WooCommerceConnector | App\Connectors (Phase 2) | authenticate, getProducts, getOrders |
| ShopifyConnector | App\Connectors (Phase 2) | authenticate, getProducts, getOrders |
| YouCanConnector | App\Connectors (Phase 2) | authenticate, getProducts, getOrders |
| WooCommerceConnector | App\Services\Connectors (Phase 1) | testConnection, fetchProducts, fetchOrders |
| ShopifyConnector | App\Services\Connectors (Phase 1) | testConnection, fetchProducts, fetchOrders |
| YouCanConnector | App\Services\Connectors (Phase 1) | testConnection, fetchProducts, fetchOrders |

> Phase 2 connectors are used by ProductSyncService. Phase 1 connectors are used by the legacy SyncPlatformOrders job.

---

## PHASE STATUS

| Phase | Status | Description |
|-------|--------|-------------|
| 1 | DONE | User auth, registration, 2FA, email verification |
| 2 | DONE | Stores CRUD, platform connections, connector wizard |
| 3 | DONE | Orders sync, order dashboard, status management |
| 4 | IN PROGRESS | Products sync, warehouse management, stock tracking |
| 5 | IN PROGRESS | WhatsApp automation, Meta webhook, AI reply handling |

---

## ISSUES FIXED (this session)

- `getDefaultWarehouse()` missing on Store model — added method; rewrote `getPrimaryWarehouse()` to auto-create
- `Undefined array key "external_id"` — `BaseConnector::normalizeProduct()` whitelist stripped the key; added it
- Dashboard 500 on null store — `redirect()` in Livewire mount does not halt; added `return` + default `$stats` structure
- WhatsApp webhook 404 — routes only had `/message` sub-path; added `GET /` (verify) and `POST /` (handle)
- WhatsApp verify 403 — `whatsapp_webhook_verify_token` is encrypted; replaced SQL `where()` with PHP-side decryption
- PHP SQLite not loaded in tests — Laragon PHP 8.4 had no `php.ini`; created one enabling `pdo_sqlite`

---

## NEXT STEPS

1. Complete product variant sync in `ProductSyncService`
2. Wire stock movement records on sync (quantity updates)
3. Build WhatsApp setup wizard completion flow
4. Add Meta webhook signature verification (X-Hub-Signature-256)
5. Add Shopify/YouCan product sync tests

---

## RECOMMENDATIONS

- **Encrypted column queries** — never use `WHERE` on encrypted columns; store a HMAC hash alongside for lookups
- **Connector normalization** — `normalizeProduct()` must explicitly include every field consumers need; document the contract
- **Retry logic** — add exponential backoff to connector HTTP calls (currently fails silently on rate limits)
- **Queue monitoring** — add `SyncLog` failure alerts; currently failed syncs are only visible in the log file
- **Test coverage** — Phase 2 connector methods (`getProducts`, `getOrders`) have no feature tests
