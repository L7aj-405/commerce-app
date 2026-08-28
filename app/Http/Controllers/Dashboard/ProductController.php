<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\ProductPublishJob;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductPublishBatch;
use App\Models\ProductPublishResult;
use Illuminate\Support\Str;
use App\Models\AgentActivityEvent;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Activity\AgentActivityRecorder;
use App\Services\Catalog\ProductStockSnapshotService;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Publishing\ProductPublishReadinessService;
use App\Services\Sync\ProductPublishService;
use App\Services\Sync\ProductPushService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Products/Index', [
                'products'    => ['data' => [], 'links' => []],
                'filters'     => [],
                'connections' => [],
            ]);
        }

        $connections = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->whereIn('platform', ['woocommerce', 'shopify', 'youcan'])
            ->get(['id', 'platform', 'label', 'status', 'synced_products_count', 'last_synced_at'])
            ->map(fn($c) => [
                'id'                     => $c->id,
                'platform'               => $c->platform,
                'label'                  => $c->label ?? ucfirst($c->platform),
                'status'                 => $c->status,
                'synced_products_count'  => (int) ($c->synced_products_count ?? 0),
                'last_synced_at'         => $c->last_synced_at?->diffForHumans(),
            ]);

        $filters = [
            'search' => $request->input('search'),
            'platform' => $request->input('platform'),
            'connection_id' => $request->input('connection_id'),
            'has_listing' => $request->boolean('has_listing') ?: null,
            'no_history' => $request->boolean('no_history') ?: null,
            'archived' => $request->boolean('archived') ?: null,
        ];

        $products = Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
            ->with(['channelListings:id,product_id,platform_connection_id,sync_status', 'channelListings.connection:id,platform'])
            ->when($request->filled('search'), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(function ($subQuery) use ($term) {
                    $subQuery->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            // Archived products are hidden from the default catalog view — they
            // stay fully intact (never soft-deleted) so historical order/return
            // display is never affected, just excluded from this listing.
            ->when($filters['archived'], fn ($q) => $q->where('status', 'archived'))
            ->when(! $filters['archived'], fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($filters['connection_id'], fn ($q) => $q->whereHas(
                'channelListings',
                fn ($sub) => $sub->where('platform_connection_id', $filters['connection_id'])
            ))
            ->when($filters['platform'] && ! $filters['connection_id'], fn ($q) => $q->whereHas(
                'channelListings.connection',
                fn ($sub) => $sub->where('platform', $filters['platform'])
            ))
            ->when($filters['has_listing'], fn ($q) => $q->whereHas('channelListings'))
            // Fast heuristic only (POS lines, returns, stock transfers, non-zero
            // legacy stock) — it never scans the online orders.items JSON blob,
            // so a product it marks "no history" can still be blocked by the
            // authoritative ProductCleanupSafetyService check at purge time.
            ->when($filters['no_history'], fn ($q) => $q
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('pos_order_items')
                    ->whereColumn('pos_order_items.product_id', 'products.id'))
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('order_return_items')
                    ->whereColumn('order_return_items.product_id', 'products.id'))
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('stock_transfer_items')
                    ->whereColumn('stock_transfer_items.product_id', 'products.id'))
                ->whereDoesntHave('stocks', fn ($sub) => $sub->where(fn ($w) => $w->where('quantity', '!=', 0)->orWhere('reserved', '!=', 0))))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Dashboard/Products/Index', [
            'store'       => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'products'    => $products,
            'filters'     => $filters,
            'connections' => $connections,
        ]);
    }

    public function syncFromPlatform(Request $request, string $platform, ProductSyncService $sync): RedirectResponse
    {
        abort_unless(in_array($platform, ['woocommerce', 'shopify', 'youcan'], true), 404);

        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $connection = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();

        if ($connection === null) {
            return back()->with('error', ucfirst($platform) . ' is not connected for this store.');
        }

        try {
            $result = $sync->syncFromPlatform($store, $platform);

            $connection->update([
                'last_synced_at'        => now(),
                'synced_products_count' => (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0),
            ]);

            return back()->with(
                'success',
                sprintf(
                    'Synced from %s: %d created, %d updated, %d failed.',
                    ucfirst($platform),
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['failed']  ?? 0,
                ),
            );
        } catch (Throwable $e) {
            Log::error('Product sync from platform failed', [
                'store_id' => $store->id,
                'platform' => $platform,
                'error'    => $e->getMessage(),
            ]);

            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function create(): Response
    {
        return Inertia::render('Dashboard/Products/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        // 1. تحديد الـ Store الحالي للمستخدم (Multi-tenancy)
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        // 2. الـ Validation مع احترام الـ store_id ف الـ SKU
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'sku'            => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'sku')->where('store_id', $store->id) // تأكيد الـ Unique على مستوى المتجر
            ],
            'description'    => 'nullable|string',
            'category'       => 'nullable|string',
            'price'          => 'required|numeric|min:0',
            'compare_price'  => 'nullable|numeric|min:0',
            'cost'           => 'nullable|numeric|min:0',
            'featured_image' => 'nullable|string',
            'type'           => 'required|in:simple,variable',

            // Canonical option definitions + per-variant option-value map —
            // see ProductVariantWizardService for how these become
            // ProductAttribute/ProductAttributeValue rows.
            'options'            => 'required_if:type,variable|array',
            'options.*.name'     => 'required_if:type,variable|string|max:120',
            'options.*.values'   => 'required_if:type,variable|array|min:1',
            'options.*.values.*' => 'required|string|max:120',
            'variants'           => 'required_if:type,variable|array',
            'variants.*.sku'     => 'nullable|string',
            'variants.*.price'   => 'required_if:type,variable|numeric|min:0',
            'variants.*.stock'   => 'nullable|integer|min:0', // initial bootstrap quantity for a brand-new variant only
            'variants.*.options' => 'required_if:type,variable|array',
        ]);

        DB::beginTransaction();

        try {
            // 3. تحديد الـ Warehouse الأساسي للمتجر لحفظ المخزون (نفس الـ Logic ديال الـ update)
            $warehouse = $store->getPrimaryWarehouse()
                ?? auth()->user()->warehouses()->where('is_active', true)->first();

            if (!$warehouse) {
                throw new \Exception('No active warehouse configured for stock mapping.');
            }

            // 4. إنشاء المنتج الرئيسي مع الـ store_id
            $product = Product::create([
                'store_id'       => $store->id, // 👈 ضروري جداً لـ Multi-tenancy
                'name'           => $validated['name'],
                'sku'            => $validated['sku'],
                'description'    => $validated['description'] ?? null,
                'category'       => $validated['category'] ?? null,
                'price'          => (float) $validated['price'],
                'compare_price'  => ($validated['compare_price'] ?? null) ? (float) $validated['compare_price'] : null,
                'cost'           => ($validated['cost'] ?? null) ? (float) $validated['cost'] : 0.0,
                'featured_image' => $validated['featured_image'] ?? null,
                'type'           => $validated['type'],
                'status'         => 'active', // الـ default status عند الإنشاء
            ]);

            // 5. إدارة المخزون وحفظ الـ Variants بناءً على الـ Type
            if ($validated['type'] === 'simple') {
                // للمنتج العادي: إنشاء الـ Stock ديريكت ف الـ Warehouse
                Stock::create([
                    'product_id'   => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'variant_id'   => null,
                    'quantity'     => 0, // أو صيفط الـ qty الافتراضي من الـ frontend
                    'reorder_level' => 10
                ]);
            } else {
                $submittedVariants = array_values($validated['variants'] ?? []);
                $sync = app(ProductVariantWizardService::class)->sync(
                    $product,
                    $validated['options'] ?? [],
                    $submittedVariants,
                );

                // Initial stock bootstrap is fine here — the product is brand
                // new, there's no existing quantity to blindly overwrite.
                // sync() returns variants in the same order they were
                // submitted, so a plain index zip is safe (no matching to a
                // prior state needed, unlike update()).
                foreach ($sync['variants']->values() as $index => $variant) {
                    Stock::create([
                        'product_id'   => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'variant_id'   => $variant->id,
                        'quantity'     => (int) ($submittedVariants[$index]['stock'] ?? 0),
                        'reorder_level' => 10,
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('dashboard.products.index')->with('success', 'Product created successfully!');
        } catch (ValidationException $e) {
            DB::rollBack();
            return back()->withInput()->withErrors($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product creation failed', ['error' => $e->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'Something went wrong: ' . $e->getMessage()]);
        }
    }

    public function edit(Request $request, Product $product, ProductPublishReadinessService $readiness, ProductStockSnapshotService $stockSnapshot): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $warehouse = $store->getPrimaryWarehouse();

        // تحميل الـ relations والـ stocks لـ كاع الأنواع
        $product->load([
            // Legacy compatibility source only — see the per-variant stock
            // computation below. The inventory engine (InventoryItem ->
            // WarehouseInventoryBalance) is the real source of truth.
            'variants.stocks',
            'variants.channelListings.connection:id,platform,label',
            'variants.inventoryLink.inventoryItem:id,sku,name',
            'variants.inventoryLink.inventoryItem.balances',
            // Canonical option data — the actual source of truth for a
            // variant's combination, not the legacy `attributes` JSON column.
            // Only active values: a value the user removed (and a variant's
            // link to it) must never resurface here, whether the variant
            // that referenced it is active or was archived for that removal.
            'variants.attributeValues' => fn($q) => $q->active(),
            'variants.attributeValues.attribute',
            'stocks',
            'channelListings.connection:id,platform,label',
            'inventoryLink.inventoryItem:id,sku,name',
            'inventoryLink.inventoryItem.balances',
            'attributes.values' => fn($q) => $q->active(),
        ]);

        // Simple product stock — same InventoryItem -> WarehouseInventoryBalance
        // source of truth as variants (see ProductStockSnapshotService), not the
        // legacy sellableStocks() sum.
        $stockSnapshot->applyToProduct($product, $warehouse);
        $product->total_stock = $product->stock_on_hand;

        // Computed BEFORE the variant mutations below, and never again after
        // — ProductPublishReadinessService (via ProductOptionSnapshot::build)
        // calls $product->load(['variants...']) internally, which replaces
        // `variants` with a freshly-queried collection of brand new
        // ProductVariant instances. Doing that after the stock/options props
        // below are set silently discarded every one of them (they were set
        // on model instances that no longer exist in $product's relation).
        $readinessCheck = $readiness->check($product);

        // product.type is authoritative: a simple product must show empty
        // options/variants here no matter what stale canonical rows are
        // still sitting active in the DB (e.g. left over from before it was
        // reverted from variable to simple by an external sync) — the edit
        // page must never resurface them, and generate-variants starts from
        // a clean slate. Plain {name, values[]} shape the wizard's Options
        // section reads — built from the canonical tables, not the JSON column.
        if ($product->isVariable()) {
            $product->options = $product->attributes
                ->filter(fn($attribute) => $attribute->values->isNotEmpty())
                ->map(fn($attribute) => [
                    'name' => $attribute->name,
                    'values' => $attribute->values->pluck('value')->all(),
                ])
                ->values();

            $product->variants->each(function ($variant) use ($warehouse, $stockSnapshot) {
                $variant->options = $variant->attributeValues
                    ->mapWithKeys(fn($value) => [$value->attribute->name => $value->value]);

                $stockSnapshot->applyToVariant($variant, $warehouse);
            });
        } else {
            $product->options = collect();
            $product->setRelation('variants', collect());
        }

        return Inertia::render('Dashboard/Products/Edit', [
            'product'     => $product,
            'connections' => $store->connections()
                ->where('status', 'active')
                ->get(['id', 'platform', 'label', 'status']),
            'warehouses'  => $store->warehouses()->get(['warehouses.id', 'warehouses.name']),
            // Informational only — never blocks saving the product, only
            // blocks the publish action client-side for a blocked platform.
            'readiness'   => $readinessCheck,
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        /*
     * Shopify/WooCommerce imported products may not have a parent product SKU.
     * But our SaaS update validation requires product.sku.
     *
     * If the user edits a Shopify-imported product with sku = null, validation
     * would stop before ProductVariantWizardService runs, so option/value removals
     * and generated variant SKUs would never be persisted.
     */
        if (! $request->filled('sku')) {
            $request->merge([
                'sku' => $this->generateProductSku(
                    $request->input('name') ?: $product->name,
                    $store->id,
                    $product->id
                ),
            ]);
        }

        $warnings = [];

        try {
            $validated = $request->validate([
                'name'           => ['required', 'string', 'max:255'],
                'sku'            => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('products', 'sku')
                        ->where('store_id', $store->id)
                        ->ignore($product->id),
                ],
                'type'           => ['required', 'in:simple,variable'],
                'description'    => ['nullable', 'string'],
                'category'       => ['nullable', 'string', 'max:120'],
                'price'          => ['required', 'numeric', 'min:0'],
                'compare_price'  => ['nullable', 'numeric', 'min:0'],
                'cost'           => ['nullable', 'numeric', 'min:0'],
                'featured_image' => ['nullable', 'url', 'max:500'],
                'status'         => ['required', 'in:active,draft,archived'],

                // Quantity is intentionally NOT accepted here.
                // Stock must be changed through adjustStock(), not product edit.
                'options'            => ['nullable', 'array'],
                'options.*.name'     => ['required_with:options', 'string', 'max:120'],
                'options.*.values'   => ['required_with:options', 'array', 'min:1'],
                'options.*.values.*' => ['required', 'string', 'max:120'],

                'variants'                  => ['nullable', 'array'],
                'variants.*.id'             => ['nullable', 'string', 'max:26'],
                'variants.*.sku'            => ['nullable', 'string', 'max:255'],
                'variants.*.price'          => ['nullable', 'numeric', 'min:0'],
                'variants.*.compare_price'  => ['nullable', 'numeric', 'min:0'],
                'variants.*.cost'           => ['nullable', 'numeric', 'min:0'],
                'variants.*.name'           => ['nullable', 'string'],
                'variants.*.options'        => ['nullable', 'array'],
                'variants.*.options.*'      => ['nullable', 'string', 'max:120'],
                'variants.*.selected'       => ['nullable', 'boolean'],

                // When true, ProductVariantWizardService should regenerate every
                // active variant SKU. Otherwise, it only fills empty SKUs.
                'regenerate_skus' => ['nullable', 'boolean'],
            ]);

            DB::transaction(function () use ($product, $validated, $store, &$warnings) {
                /*
             * If a variable product becomes simple, remove local active variants.
             * This branch keeps the old behavior, but quantity still remains
             * inventory-safe because we do not write product.qty here.
             */
                if ($product->type === 'variable' && $validated['type'] === 'simple') {
                    Stock::whereIn('variant_id', $product->variants()->pluck('id'))->delete();
                    // Archives (soft-deletes) every active variant and
                    // retires every active option/value — never hard-deletes
                    // a variant that still carries a channel listing or
                    // inventory link. Without this, stale canonical options
                    // stay active and keep blocking Shopify/WooCommerce
                    // readiness for a product that now looks simple.
                    app(ProductVariantWizardService::class)->archiveAll($product);
                }

                $product->update([
                    'name'           => $validated['name'],
                    'sku'            => $validated['sku'],
                    'type'           => $validated['type'],
                    'description'    => $validated['description'] ?? null,
                    'category'       => $validated['category'] ?? null,
                    'price'          => (float) $validated['price'],
                    'compare_price'  => ($validated['compare_price'] ?? null) !== null && $validated['compare_price'] !== ''
                        ? (float) $validated['compare_price']
                        : null,
                    'cost'           => ($validated['cost'] ?? null) !== null && $validated['cost'] !== ''
                        ? (float) $validated['cost']
                        : 0.0,
                    'featured_image' => $validated['featured_image'] ?? null,
                    'status'         => $validated['status'],
                ]);

                $warehouse = $store->getPrimaryWarehouse()
                    ?? auth()->user()->warehouses()->where('is_active', true)->first();

                if (! $warehouse) {
                    throw new \RuntimeException('No active warehouse configured for stock mapping.');
                }

                if ($validated['type'] === 'simple') {
                    Stock::firstOrCreate(
                        [
                            'product_id'   => $product->id,
                            'warehouse_id' => $warehouse->id,
                            'variant_id'   => null,
                        ],
                        [
                            'quantity'      => 0,
                            'reorder_level' => 10,
                        ]
                    );

                    return;
                }

                $incomingVariants = collect($validated['variants'] ?? [])
                    ->filter(fn($variantData) => $variantData['selected'] ?? true)
                    ->values()
                    ->all();

                $sync = app(ProductVariantWizardService::class)->sync(
                    $product,
                    $validated['options'] ?? [],
                    $incomingVariants,
                    (bool) ($validated['regenerate_skus'] ?? false),
                );

                $warnings = $sync['warnings'] ?? [];

                /*
             * Bootstrap stock rows only for new variants.
             * Do not overwrite existing stock quantities from the product wizard.
             */
                foreach ($sync['variants'] as $variant) {
                    Stock::firstOrCreate(
                        [
                            'product_id'   => $product->id,
                            'variant_id'   => $variant->id,
                            'warehouse_id' => $warehouse->id,
                        ],
                        [
                            'quantity'      => 0,
                            'reorder_level' => 10,
                        ]
                    );
                }
            });

            $redirect = redirect()
                ->route('dashboard.products.index')
                ->with('success', 'Product updated successfully.');

            if ($warnings !== []) {
                $redirect = $redirect->with('warning', implode(' ', $warnings));
            }

            return $redirect;
        } catch (ValidationException $e) {
            Log::warning('Product update validation failed', [
                'product_id' => $product->id,
                'errors' => $e->errors(),
            ]);

            return back()->withInput()->withErrors($e->errors());
        } catch (Throwable $e) {
            Log::error('Product update failed', [
                'product_id' => $product->id,
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return back()->withInput()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    private function generateProductSku(?string $name, string $storeId, string $ignoreProductId): string
    {
        $base = strtoupper(Str::slug((string) $name, '-'));

        if ($base === '') {
            $base = 'PRODUCT-' . strtoupper(substr($ignoreProductId, 0, 8));
        }

        // Keep enough room for "-2", "-3", etc.
        $base = substr($base, 0, 180);

        $sku = $base;
        $suffix = 2;

        while (
            Product::query()
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->where('id', '!=', $ignoreProductId)
            ->exists()
        ) {
            $sku = $base . '-' . $suffix;
            $suffix++;
        }

        return $sku;
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $product->delete();

        return redirect()->route('dashboard.products.index')->with('success', 'Product deleted.');
    }

    /**
     * Publish a single product to explicitly selected platform connections.
     * Replaces the old push() action, which auto-published to every active
     * connection for the store regardless of platform — that is exactly the
     * "publish leaks to every connected platform" bug this endpoint fixes.
     * connection_ids is required and is always re-verified against this
     * product's own store; nothing from the request is trusted as-is.
     */
    public function publish(Request $request, Product $product, ProductPublishService $service): \Illuminate\Http\JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $validated = $request->validate([
            'connection_ids' => ['required', 'array', 'min:1'],
            'connection_ids.*' => ['string'],
            'create_missing_listings' => ['nullable', 'boolean'],
        ]);

        if ($product->isVariable() && ! $product->variants()->exists()) {
            return response()->json(['message' => 'Add at least one variant before publishing a variable product.'], 422);
        }

        return response()->json($service->publish(
            $product,
            $store,
            $validated['connection_ids'],
            (bool) ($validated['create_missing_listings'] ?? false),
        ));
    }

    /**
     * Queue-based publish (CV5) — creates a ProductPublishBatch and one
     * ProductPublishResult per explicitly selected connection, dispatches a
     * ProductPublishJob for each, and returns immediately. Unlike publish()
     * above, the browser never waits for the platform HTTP calls — poll
     * publishBatchStatus() for outcomes. connection_ids is required and is
     * always re-verified against this product's own store, same as publish().
     */
    public function publishQueued(Request $request, Product $product): \Illuminate\Http\JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $validated = $request->validate([
            'connection_ids' => ['required', 'array', 'min:1'],
            'connection_ids.*' => ['string'],
            'create_missing_listings' => ['nullable', 'boolean'],
        ]);

        $createMissingListings = (bool) ($validated['create_missing_listings'] ?? false);

        $connections = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $validated['connection_ids'])
            ->whereIn('platform', ['shopify', 'woocommerce'])
            ->get();

        $batch = ProductPublishBatch::create([
            'store_id' => $store->id,
            'organization_id' => $store->organization_id,
            'user_id' => $request->user()->id,
            'status' => ProductPublishBatch::STATUS_PENDING,
            'total_count' => 0,
            'payload' => ['product_id' => $product->id, 'create_missing_listings' => $createMissingListings],
        ]);

        $results = [];
        $foundIds = $connections->pluck('id')->all();

        foreach ($validated['connection_ids'] as $requestedId) {
            if (! in_array($requestedId, $foundIds, true)) {
                // Wrong store, wrong platform, or doesn't exist — recorded as
                // an immediate failure, never silently dropped, never queued.
                $results[] = ProductPublishResult::create([
                    'batch_id' => $batch->id,
                    'product_id' => $product->id,
                    'platform_connection_id' => $requestedId,
                    'platform' => 'unknown',
                    'status' => ProductPublishResult::STATUS_FAILED,
                    'message' => "Connection does not belong to this product's store.",
                    'error_code' => 'invalid_connection',
                ]);
            }
        }

        foreach ($connections as $connection) {
            $result = ProductPublishResult::create([
                'batch_id' => $batch->id,
                'product_id' => $product->id,
                'platform_connection_id' => $connection->id,
                'platform' => $connection->platform,
                'status' => ProductPublishResult::STATUS_QUEUED,
            ]);

            $results[] = $result;

            ProductPublishJob::dispatch($result->id, $createMissingListings);
        }

        $batch->update(['total_count' => count($results)]);

        return response()->json([
            'batch_id' => $batch->id,
            'status' => 'queued',
            'connections' => $connections->map(fn($c) => ['id' => $c->id, 'platform' => $c->platform])->values(),
        ]);
    }

    /** Poll the outcome of a queued publish batch — scoped to the acting user's active store. */
    public function publishBatchStatus(Request $request, string $batch): \Illuminate\Http\JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $model = ProductPublishBatch::query()
            ->where('store_id', $store->id)
            ->with('results')
            ->find($batch);

        abort_if($model === null, 404);

        return response()->json([
            'batch_id' => $model->id,
            'status' => $model->status,
            'total_count' => $model->total_count,
            'succeeded_count' => $model->succeeded_count,
            'failed_count' => $model->failed_count,
            'skipped_count' => $model->skipped_count,
            'results' => $model->results->map(fn($r) => [
                'connection_id' => $r->platform_connection_id,
                'platform' => $r->platform,
                'status' => $r->status,
                'message' => $r->message,
                'variant_id' => $r->product_variant_id,
                'external_product_id' => $r->external_product_id,
                'external_variant_id' => $r->external_variant_id,
            ]),
        ]);
    }

    /**
     * Publish several products to explicitly selected platform connections
     * in one request. Every product is re-scoped to the active store —
     * ids for another store's products are silently excluded from the
     * result set, never published.
     */
    public function bulkPublish(Request $request, ProductPublishService $service): \Illuminate\Http\JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
            'connection_ids' => ['required', 'array', 'min:1'],
            'connection_ids.*' => ['string'],
            'create_missing_listings' => ['nullable', 'boolean'],
        ]);

        return response()->json($service->bulkPublish(
            $validated['product_ids'],
            $store,
            $validated['connection_ids'],
            (bool) ($validated['create_missing_listings'] ?? false),
        ));
    }

    /**
     * Inventory-safe stock adjustment — replaces the old "write product.qty
     * directly" flow. Goes through CatalogInventoryService/InventoryEngine
     * (ledger entry + WarehouseInventoryBalance), then synchronously pushes
     * the new quantity to WooCommerce and Shopify (YouCan untouched — its
     * existing async webhook sync path is unaffected either way). Shopify
     * quantity is set via InventoryLevel (inventory_item_id + location_id),
     * never via a product/variant update payload. A platform push failure
     * never rolls back the local adjustment — it already committed.
     */
    public function adjustStock(
        Request $request,
        Product $product,
        CatalogInventoryService $catalog,
        InventoryEngine $engine,
        ProductPushService $pushService,
        AgentActivityRecorder $activity,
    ): RedirectResponse {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'string'],
            'type'         => ['required', 'in:set_on_hand,increase,decrease'],
            'quantity'     => ['required', 'integer', 'min:0'],
            'reason'       => ['required', 'string', 'max:255'],
            'variant_id'   => ['nullable', 'string'],
        ]);

        if ($validated['type'] !== 'set_on_hand' && (int) $validated['quantity'] < 1) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity of at least 1.']);
        }

        $warehouse = $this->resolveWarehouse($store, $validated['warehouse_id']);
        abort_if($warehouse === null, 422, 'Warehouse not found for this store.');

        $variant = null;
        if (! empty($validated['variant_id'])) {
            // Scoped through the parent product — a crafted ULID from another
            // product must never be adjustable here.
            $variant = $product->variants()->whereKey($validated['variant_id'])->firstOrFail();
        }

        try {
            $item = $catalog->forCatalog($product, $variant);

            if ($item === null) {
                return back()->with('error', 'Could not resolve an inventory item for this product.');
            }

            $actor = $request->user();
            $qty   = (int) $validated['quantity'];

            match ($validated['type']) {
                'set_on_hand' => $engine->setOnHand($item, $warehouse, $qty, 'adjustment', $product, $actor, $validated['reason']),
                'increase'    => $engine->adjustOnHand($item, $warehouse, $qty, 'adjustment', $product, $actor, $validated['reason']),
                'decrease'    => $engine->adjustOnHand($item, $warehouse, -$qty, 'adjustment', $product, $actor, $validated['reason']),
            };

            $activity->record($actor, $store, AgentActivityEvent::INVENTORY_ADJUSTED, 'inventory', [
                'subject' => $product,
                'metadata' => ['warehouse_id' => $warehouse->id, 'reason' => $validated['reason'], 'type' => $validated['type']],
            ]);
        } catch (ValidationException $e) {
            // Local inventory is untouched — the engine's own transaction
            // rolled back before anything was written.
            return back()->withErrors($e->errors());
        }

        // Local adjustment is already committed — nothing below can roll it
        // back. Push to WooCommerce and Shopify explicitly (YouCan keeps
        // its existing async webhook sync path, untouched).
        $wooResults = $variant !== null
            ? $pushService->pushVariantStock($variant, 'woocommerce')
            : $pushService->pushStock($product, 'woocommerce');

        $shopifyResults = $variant !== null
            ? $pushService->pushVariantStock($variant, 'shopify')
            : $pushService->pushStock($product, 'shopify');

        $message = $this->describeStockPushResult($wooResults, 'WooCommerce')
            . ' ' . $this->describeStockPushResult($shopifyResults, 'Shopify');

        return redirect()
            ->route('dashboard.products.edit', $product)
            ->with('success', "Stock updated locally. {$message}");
    }

    private function describeStockPushResult(array $results, string $platformLabel): string
    {
        if (empty($results)) {
            return "No {$platformLabel} listing for this store — nothing pushed.";
        }

        $succeeded = collect($results)->where('success', true)->count();
        $total     = count($results);

        if ($succeeded === $total) {
            return "{$platformLabel}: synced.";
        }

        if ($succeeded > 0) {
            return "{$platformLabel}: synced {$succeeded}/{$total}.";
        }

        $error = collect($results)->pluck('message')->filter()->first();

        return "{$platformLabel} stock sync failed" . ($error ? ": {$error}" : '.');
    }

    private function resolveWarehouse(Store $store, string $warehouseId): ?Warehouse
    {
        return $store->warehouses()->where('warehouses.id', $warehouseId)->first();
    }
}
