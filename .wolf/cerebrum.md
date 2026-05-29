# Cerebrum

> OpenWolf's learning memory. Updated automatically as the AI learns from interactions.
> Do not edit manually unless correcting an error.
> Last updated: 2026-05-15

## User Preferences

- **UI Design system**: Uses utility classes defined in `app.css` (`@layer components`): `.card`, `.btn`, `.btn-primary`, `.btn-secondary`, `.btn-danger`, `.btn-success`, `.btn-ghost`, `.input`, `.label`, `.badge`, `.badge-{color}`, `.table-container`, `.table-th`, `.table-td`, `.table-row`, `.page-title`, `.page-subtitle`, `.filter-bar`. Always use these instead of raw Tailwind strings.
- **Dark mode**: Class-based (`darkMode: 'class'` in tailwind). Toggle managed by Alpine.js `x-data` on `<body>` + inline `<head>` script to prevent FOUC. Button dispatches via `darkMode = !darkMode`.
- **Icons**: Heroicons outline SVG inline (viewBox="0 0 24 24", stroke-width="1.7"), never emojis, never external icon fonts.
- **Font**: Inter from Bunny Fonts (switched from Figtree).
- **Empty states**: Use dedicated card with centered SVG icon + title + subtitle, never just "No X found" text.
- **Action buttons in tables**: Use `.btn-icon` with SVG icons + `sr-only` labels for accessibility.

<!-- How the user likes things done. Code style, tools, patterns, communication. -->

## Key Learnings

- **Per-platform external_ids:** `Product.external_id` and `ProductVariant.external_id` store only ONE platform's ID (last push wins). For multi-platform, use `SyncLog::where('entity_id', $id)->where('platform', $platform)->where('status','success')->value('external_id')` to get the correct ID per platform. Both `VariantPushService` and `ProductPushService` have private helpers for this.
- **Shopify options architecture:** Product options (Size/Color) are separate from variant option values (L/Red). When creating a variable product with no options, Shopify creates a default "Title"/"Default Title" setup. Always check and update product options (`getOrSyncProductOptions`) before creating variants; replacing "Title" with your attribute names is safe and Shopify auto-handles the default variant.
- **WooCommerce attribute/variation link:** Variable product attributes must be defined on the product itself (with `variation:true`) BEFORE creating variations. The variation's `attributes` array is matched against the product's attribute list — if absent, WooCommerce silently stores the variation without attributes. `createVariableProduct()` solves this by including the attribute definitions in the initial product POST payload.
- **Product attributes are per-product, NOT per-store:** `product_attributes.product_id` (FK → products). The unique constraint is `(product_id, slug)`, so Product A and Product B can each have their own "color" attribute independently. Use `ProductAttribute::findOrCreateForProduct($productId, $name)` — never `findOrCreateForStore`. Query with `where('product_id', $product->id)` or via `$product->attributes()`. Never scope by `store_id`.
- **Per-product attribute sync from platform:** When `ProductSyncService::createVariants()` saves a variant with a `attributes` JSON map, `syncVariantAttributesToPerProduct()` is called to populate `product_attributes`, `product_attribute_values`, and `product_variant_attribute_values` pivot. This makes platform-synced variants immediately usable in the Attribute Manager UI.
- **Variable product creation flow:** `ProductPushService::createProduct()` auto-routes to `createVariableProduct()` when `product->isVariable()`. All 3 connectors implement `createVariableProduct(array $productData)` accepting the normalized structure from `gatherVariableProductData()`. Returns `{success, external_id, variant_ids: [local_id => platform_id], message}`. Shopify sends product+variants in ONE POST; WooCommerce and YouCan need sequential variant POSTs after the product is created.
- **Shopify variant matching post-creation:** When Shopify creates variants in the product POST, match them back to local variant IDs by SKU (`$skuToShopifyId` lookup). Empty SKUs won't match — always ensure variants have a SKU before pushing.
- **gatherVariableProductData() structure:** `['type','name','sku','price','description','status','attributes' => ['name' => [values...]], 'variants' => [['local_id','name','sku','price','attributes','stock'],...]]`. `local_id` is the ULID used to persist `variant.external_id` after platform responds.

- **Project:** saas-commerce
- **Connector architecture:** Phase 1 connectors live in `App\Services\Connectors\` (legacy, use `fetchProducts/fetchOrders`). Phase 2 connectors live in `App\Connectors\` (new, use `getProducts/getOrders/authenticate`). Old and new run in parallel — the old factory (`App\Services\Connectors\ConnectorFactory`) creates Phase 1; the new factory (`App\Factories\ConnectorFactory`) creates Phase 2.
- **SyncService is now an orchestrator:** It takes `ProductSyncService` and `OrderSyncService` via constructor DI. Any code doing `new SyncService()` is broken — jobs/Livewire components must use `app(SyncService::class)` or Laravel handle() injection.
- **SyncPlatformOrders job:** Updated to use handle(SyncService $syncService) injection — no longer does `new SyncService()`.
- **YouCan price parsing:** Values >= 10,000 are treated as centimes (divide by 100). Verify against live API.
- **Shopify pagination:** REST API uses cursor-based pagination via Link headers (page_info token), not page numbers. Current implementation only fetches first page up to $perPage — full cursor support needs page_info tracking.
- **Stock initialization:** `createStocks()` sets quantity=0 on first sync; actual quantities should come from platform stock movement events, not assumed from sync.
- **Variant attribute rows in Livewire:** Dynamic attribute rows use an array of `['key'=>'','value'=>'']` structs named `$variantAttributes`. `removeAttributeRow(int $index)` must call `array_values()` after `unset()` to reindex so Livewire `wire:model` indices stay contiguous.
- **Livewire sub-components in tabs:** Embedded `<livewire:products.product-variants>` and `<livewire:products.product-stock>` inside Alpine.js `x-show` tabs work without `#[Layout]` — never add Layout attribute to sub-components that are embedded, not route-accessed.
- **Product edit uses Alpine.js tabs:** `x-data="{ tab: 'basic' }"` with `x-show` + `x-transition` on each panel. Tab buttons use `:class` binding for active state (border-blue-600 text-blue-600 vs border-transparent text-gray-500).
- **`wasRecentlyCreated` for StockMovement audit:** `Stock::updateOrCreate()` returns the model; check `$stock->wasRecentlyCreated` to only log `initial_sync` movement on first creation, not on every sync update.

## Do-Not-Repeat

[2026-05-26] **Double finalize() in creation wizard causes duplicate SKU 1062:** `openPushModal()` called `finalize('draft')` to save variants, then `confirmPush()` called `finalize('active')` again. The first call creates variants via INSERT; the second call soft-deletes them (deleted_at set) then tries to INSERT same SKUs — MySQL unique index sees soft-deleted rows and throws 1062. Fix: remove `finalize()` from `openPushModal()` (use `autosave()` only); in `finalize()` use `forceDelete()` to hard-delete variants so their SKU slots are freed. Also: `product_variants.sku` unique is per-product `(product_id, sku)` not global.

<!-- Mistakes made and corrected. Each entry prevents the same mistake recurring. -->
<!-- Format: [YYYY-MM-DD] Description of what went wrong and what to do instead. -->

[2026-05-25] **product_attributes scoped to store instead of product:** The original `product_attributes` table had `store_id` + unique `(store_id, slug)`. This meant ALL products in a store shared a single "color" attribute — editing one product's color affected every other product. The Livewire component queried `where('store_id', ...)`, showing every store attribute in every product's UI. Fix: migration 2026_05_25_300000 replaces `store_id` with `product_id`; `ProductAttribute::findOrCreateForProduct()` replaces `findOrCreateForStore()`; all queries now scoped to `product_id`. NEVER use `store_id` on `product_attributes`.

[2026-05-25] **Variable product pushed as empty shell (no variants/attributes):** `createProduct()` was routing ALL product types through the same simple-product path. For variable products the platform received only the product stub. Fix: `createProduct()` now checks `isVariable()` and delegates to `createVariableProduct()`, which gathers all attribute+variant data first, then sends one coordinated multi-step push per platform.

[2026-05-25] **Multi-platform variant push silently skips Shopify/YouCan:** `ProductPushService::createProduct` saves only the FIRST successful platform's external_id and stores `product->platform = 'woocommerce'`. `VariantPushService::createVariant` was using `$product->platform` to filter connections — so only WooCommerce variants were ever pushed. NEVER filter `getConnections()` by `$product->platform` in variant push methods. Always use SyncLog lookup (`getProductExternalId`) to resolve per-platform external_ids.

[2026-05-25] **SyncLog action ENUM too restrictive:** The `action` column was `ENUM('product','variant','stock','order','customer')`. Using `variant_create` or `variant_delete` caused MySQL warning 1265 (data truncated). When adding new action types to VariantPushService or ProductPushService, always update the ENUM via a new migration first.

[2026-05-25] **WooCommerce variation attributes silently ignored:** Creating a variation with attributes (size=L) via POST /products/{id}/variations has NO effect unless the parent product first has those attributes defined with `variation:true`. Always call `syncParentProductAttributes()` before posting a new variation.

[2026-05-25] **Shopify createVariant without option1/option2/option3 creates an attribute-less variant:** Shopify variants must set `option1`/`option2`/`option3` to the actual attribute values. The variant creation succeeds (returns 201) but the variant appears with empty option values. Always call `getOrSyncProductOptions()` to get the attribute-name→position map and populate the option slots.

[2026-05-24] WooCommerce `parseProduct()` does NOT embed variants — variants require a separate call to `/products/{id}/variations`. Shopify and YouCan embed variants in their product response. Always check `empty($platformProduct['variants'])` before assuming variants are absent — for WooCommerce they will be absent even for variable products.

[2026-05-24] **CRITICAL: `$this->attributes` in Eloquent models is the raw protected attribute store (all columns as raw values), NOT the cast `attributes` JSON column.** Inside model methods, ALWAYS use `$this->getAttribute('attributes')` to get the JSON-cast value. Using `$this->attributes` returns the entire raw Eloquent attribute array, causing silent bugs that are hard to detect.

[2026-05-24] **Livewire component property naming collision:** Do NOT use `$attributes` as a Livewire component property — Livewire uses it internally. Use `$variantAttributes` or similar scoped name instead.

[2026-05-24] **WooCommerce variation `name` field:** The `/products/{id}/variations` endpoint response has NO `name` field. Build the variant name by joining attribute option values: `implode(' / ', array_column($variant['attributes'], 'option'))`. Fall back to SKU or 'Variant'.

[2026-05-24] **Shopify variant option names:** Shopify variants use `option1`, `option2`, `option3` (positional), NOT named keys. To get named attributes you must fetch the full product and map `product.options[].position` → `name`. Never call a separate `/variants/{id}` endpoint — always fetch the full product to get option names.

[2026-05-24] **Dead code before match expressions:** When building connector instances with a `match($platform)` expression, make sure no stale assignment (e.g., `$connector = $syncService->someOtherMethod()`) sits immediately before the match and silently overwrites the variable. The match result is the real connector.

## Decision Log

[2026-05-26] **ProductEditWizard** — edit wizard mirrors the creation wizard but with 5 steps for both product types (no separate Attributes editing step — attributes are locked and shown as chips in step 2). `finalize()` updates variants in-place with `ProductVariant::where('id', $vd['id'])->update()` — never deletes/recreates (would break external_ids). `confirmPush()` uses `pushProduct()` if `$product->external_id` is set, `createProduct()` otherwise. `autosave()` always uses UPDATE. SKU field is `readonly` in blade (locked after creation). Route updated from `ProductEdit` to `ProductEditWizard`.

<!-- Significant technical decisions with rationale. Why X was chosen over Y. -->
