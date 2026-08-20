<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\Catalog\ProductVariantWizardService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
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
            ->map(fn ($c) => [
                'id'                     => $c->id,
                'platform'               => $c->platform,
                'label'                  => $c->label ?? ucfirst($c->platform),
                'status'                 => $c->status,
                'synced_products_count'  => (int) ($c->synced_products_count ?? 0),
                'last_synced_at'         => $c->last_synced_at?->diffForHumans(),
            ]);

        $filters = ['search' => $request->input('search')];

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
                    'reorder_level'=> 10
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
                        'reorder_level'=> 10,
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

    public function edit(Request $request, Product $product): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        // تحميل الـ relations والـ stocks لـ كاع الأنواع
        $product->load([
            'variants.stocks',
            'variants.channelListings.connection:id,platform,label',
            'variants.inventoryLink.inventoryItem:id,sku,name',
            // Canonical option data — the actual source of truth for a
            // variant's combination, not the legacy `attributes` JSON column.
            'variants.attributeValues.attribute',
            'stocks',
            'channelListings.connection:id,platform,label',
            'inventoryLink.inventoryItem:id,sku,name',
            'attributes.values',
        ]);

        // حساب الـ total_stock وتمريرها نيشان للـ Simple Product باش يعمر الـ input فـ React
        // sellableStocks() only — damaged stock must never pre-fill the editable quantity
        $product->total_stock = (int) $product->sellableStocks()->whereNull('variant_id')->sum('quantity');

        // Plain {name, values[]} shape the wizard's Options section reads —
        // built from the canonical tables, not the JSON column.
        $product->options = $product->attributes->map(fn ($attribute) => [
            'name' => $attribute->name,
            'values' => $attribute->values->pluck('value')->all(),
        ])->values();

        $product->variants->each(function ($variant) {
            $variant->options = $variant->attributeValues
                ->mapWithKeys(fn ($value) => [$value->attribute->name => $value->value]);
        });

        return Inertia::render('Dashboard/Products/Edit', [
            'product'     => $product,
            'connections' => $store->connections()
                ->where('status', 'active')
                ->get(['id', 'platform', 'label', 'status']),
            'warehouses'  => $store->warehouses()->get(['warehouses.id', 'warehouses.name']),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'sku'            => [
                'required', 
                'string', 
                'max:255', 
                Rule::unique('products', 'sku')->where('store_id', $store->id)->ignore($product->id)
            ],
            'type'           => ['required', 'in:simple,variable'],
            'description'    => ['nullable', 'string'],
            'category'       => ['nullable', 'string', 'max:120'],
            'price'          => ['required', 'numeric', 'min:0'],
            'compare_price'  => ['nullable', 'numeric', 'min:0'],
            'cost'           => ['nullable', 'numeric', 'min:0'],
            'featured_image' => ['nullable', 'url', 'max:500'],
            'status'         => ['required', 'in:active,draft,archived'],

            // NOTE: quantity is intentionally NOT accepted here. Editing product
            // fields must never write stock — use adjustStock() (inventory-safe,
            // pushes to WooCommerce) instead. See Fix WooCommerce outbound sync.
            //
            // Canonical option definitions + per-variant option-value map —
            // see ProductVariantWizardService. Options are optional on update
            // (nullable, not required_if) because a simple-type submission
            // never carries any.
            'options'            => ['nullable', 'array'],
            'options.*.name'     => ['required_with:options', 'string', 'max:120'],
            'options.*.values'   => ['required_with:options', 'array', 'min:1'],
            'options.*.values.*' => ['required', 'string', 'max:120'],
            'variants'       => ['nullable', 'array'],
            'variants.*.id'  => ['nullable', 'string', 'max:26'],
            'variants.*.sku' => ['nullable', 'string'],
            'variants.*.price'=> ['nullable', 'numeric', 'min:0'],
            'variants.*.compare_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0'],
            'variants.*.name'=> ['nullable', 'string'],
            'variants.*.options' => ['nullable', 'array'],
        ]);

        $warnings = [];

        try {
            DB::transaction(function () use ($product, $validated, $store, &$warnings) {
                // 1. تنظيف الـ Variants القدام إذا تحول السيتينغ من Variable لـ Simple
                if ($product->type === 'variable' && $validated['type'] === 'simple') {
                    Stock::whereIn('variant_id', $product->variants()->pluck('id'))->delete();
                    $product->variants()->delete();
                }

                // 2. تحديث الداتا الأساسية للمنتج
                $product->update([
                    'name'           => $validated['name'],
                    'sku'            => $validated['sku'],
                    'type'           => $validated['type'],
                    'description'    => $validated['description'] ?? null,
                    'category'       => $validated['category'] ?? null,
                    'price'          => (float) $validated['price'],
                    'compare_price'  => ($validated['compare_price'] ?? null) ? (float) $validated['compare_price'] : null,
                    'cost'           => ($validated['cost'] ?? null) ? (float) $validated['cost'] : 0.0,
                    'featured_image' => $validated['featured_image'] ?? null,
                    'status'         => $validated['status'],
                ]);

                // تحديد الـ Warehouse الأساسي للمتجر
                $warehouse = $store->getPrimaryWarehouse() 
                    ?? auth()->user()->warehouses()->where('is_active', true)->first();

                if (!$warehouse) {
                    throw new \Exception('No active warehouse configured for stock mapping.');
                }

                // 3. Bootstrap a stock row ONLY if one doesn't exist yet — never
                // overwrite an existing quantity from a product-field edit.
                // Real quantity changes go through adjustStock() (inventory-safe).
                if ($validated['type'] === 'simple') {
                    Stock::firstOrCreate(
                        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'variant_id' => null],
                        ['quantity' => 0, 'reorder_level' => 10]
                    );
                } else {
                    $incomingVariants = collect($validated['variants'] ?? [])
                        ->filter(fn ($vd) => $vd['selected'] ?? true)
                        ->values()
                        ->all();

                    $sync = app(ProductVariantWizardService::class)->sync(
                        $product,
                        $validated['options'] ?? [],
                        $incomingVariants,
                    );

                    $warnings = $sync['warnings'];

                    // Bootstrap-only, same rule as the simple-product branch
                    // above — never overwrites an existing Stock row's quantity.
                    foreach ($sync['variants'] as $variant) {
                        Stock::firstOrCreate(
                            ['product_id' => $product->id, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id],
                            ['quantity' => 0, 'reorder_level' => 10]
                        );
                    }
                }
            });

            $redirect = redirect()->route('dashboard.products.index')->with('success', 'Product updated successfully.');

            if ($warnings !== []) {
                $redirect = $redirect->with('warning', implode(' ', $warnings));
            }

            return $redirect;

        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (Throwable $e) {
            Log::error('Product update failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Error: ' . $e->getMessage());
        }
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
     * the new quantity to WooCommerce only (Shopify/YouCan untouched —
     * their existing async webhook sync path is unaffected either way).
     */
    public function adjustStock(
        Request $request,
        Product $product,
        CatalogInventoryService $catalog,
        InventoryEngine $engine,
        ProductPushService $pushService,
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
        } catch (ValidationException $e) {
            // Local inventory is untouched — the engine's own transaction
            // rolled back before anything was written.
            return back()->withErrors($e->errors());
        }

        // Local adjustment is committed. Push to WooCommerce only — pinned
        // explicitly so this new immediate-feedback path never touches
        // Shopify/YouCan.
        $wooResults = $variant !== null
            ? $pushService->pushVariantStock($variant, 'woocommerce')
            : $pushService->pushStock($product, 'woocommerce');

        $message = $this->describeStockPushResult($wooResults);

        return redirect()
            ->route('dashboard.products.edit', $product)
            ->with('success', "Stock updated locally. {$message}");
    }

    private function describeStockPushResult(array $results): string
    {
        if (empty($results)) {
            return 'No WooCommerce listing for this store — nothing pushed.';
        }

        $succeeded = collect($results)->where('success', true)->count();
        $total     = count($results);

        if ($succeeded === $total) {
            return 'WooCommerce: synced.';
        }

        if ($succeeded > 0) {
            return "WooCommerce: synced {$succeeded}/{$total}.";
        }

        $error = collect($results)->pluck('message')->filter()->first();

        return 'WooCommerce sync failed' . ($error ? ": {$error}" : '.');
    }

    private function resolveWarehouse(Store $store, string $warehouseId): ?Warehouse
    {
        return $store->warehouses()->where('warehouses.id', $warehouseId)->first();
    }
}