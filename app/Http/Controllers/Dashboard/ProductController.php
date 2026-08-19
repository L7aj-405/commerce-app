<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Services\Sync\ProductPushService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
            ->get(['id', 'platform', 'label', 'synced_products_count', 'last_synced_at'])
            ->map(fn ($c) => [
                'platform'              => $c->platform,
                'label'                 => $c->label ?? ucfirst($c->platform),
                'synced_products_count' => (int) ($c->synced_products_count ?? 0),
                'last_synced_at'        => $c->last_synced_at?->diffForHumans(),
            ]);

        $filters = ['search' => $request->input('search')];

        $products = Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
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
            
            // الـ Validation ديال الخصائص والفاريانتس المدخلة من React
            'attributes'     => 'required_if:type,variable|array',
            'variants'       => 'required_if:type,variable|array',
            'variants.*.sku' => 'required_if:type,variable|string',
            'variants.*.price'=> 'required_if:type,variable|numeric|min:0',
            'variants.*.stock'=> 'required_if:type,variable|integer|min:0', // هادي غاتمشي للـ Stocks Table
            'variants.*.options'=> 'required_if:type,variable|array',
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
                // للمنتج المتغير: Loop على الـ Variants اللي جايين من المصفوفة
                foreach ($validated['variants'] as $variantData) {
                    
                    // تحويل الـ Options (Matrix) لـ الاسم ديال الـ Variant بحال: "Size: S / Color: Red"
                    $variantName = collect($variantData['options'])
                        ->map(fn($val, $key) => "{$key}: {$val}")
                        ->implode(' / ');

                    // إنشاء الفاريانت ف جدول الـ product_variants
                    $variant = $product->variants()->create([
                        'name'       => $variantName ?: 'Variant',
                        'sku'        => $variantData['sku'] ?: null,
                        'price'      => (float) ($variantData['price'] ?: 0),
                        'cost'       => (float) ($product->cost ?: 0),
                        'attributes' => $variantData['options'], // الـ JSON اللي كيمشي لعمود الـ attributes ف جدول الـ variants
                    ]);

                    // إنشاء الـ Stock الخاص بهاد الفاريانت بالظبط ف جدول الـ stocks
                    Stock::create([
                        'product_id'   => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'variant_id'   => $variant->id, // 👈 ربط الـ Stock بـ الـ Variant الجديد
                        'quantity'     => (int) ($variantData['stock'] ?? 0),
                        'reorder_level'=> 10
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('dashboard.products.index')->with('success', 'Product created successfully!');

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
            'stocks',
            'channelListings.connection:id,platform,label',
            'inventoryLink.inventoryItem:id,sku,name',
        ]);

        // حساب الـ total_stock وتمريرها نيشان للـ Simple Product باش يعمر الـ input فـ React
        // sellableStocks() only — damaged stock must never pre-fill the editable quantity
        $product->total_stock = (int) $product->sellableStocks()->whereNull('variant_id')->sum('quantity');

        return Inertia::render('Dashboard/Products/Edit', [
            'product'     => $product,
            'connections' => $store->connections()
                ->where('status', 'active')
                ->get(['id', 'platform', 'label']),
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
            
            // الـ Validation ديال الـ qty الخاص بـ Simple Product
            'qty'            => ['nullable', 'integer', 'min:0'], 
            
            // الـ Validation الخاص بـ Variable Variants
            'variants'       => ['nullable', 'array'],
            'variants.*.id'  => ['nullable', 'string', 'max:26'],
            'variants.*.sku' => ['nullable', 'string'],
            'variants.*.price'=> ['nullable', 'numeric', 'min:0'],
            'variants.*.qty' => ['nullable', 'integer', 'min:0'],
            'variants.*.name'=> ['nullable', 'string'],
        ]);

        try {
            DB::transaction(function () use ($product, $validated, $store) {
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

                // 3. تحديث الـ Stocks حسب نوع المنتج الحلي
                if ($validated['type'] === 'simple') {
                    $stockQty = isset($validated['qty']) ? (int) $validated['qty'] : 0;

                    Stock::updateOrCreate(
                        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'variant_id' => null],
                        ['quantity' => $stockQty, 'reorder_level' => 10]
                    );
                } else {
                    $incomingVariants = $validated['variants'] ?? [];

                    foreach ($incomingVariants as $vd) {
                        if (!($vd['selected'] ?? true)) continue;

                        if (! empty($vd['id'])) {
                            // Scope through the parent product: a crafted ULID from
                            // another product must never be editable here.
                            $variant = $product->variants()->whereKey($vd['id'])->firstOrFail();
                            $variant->update([
                                'sku'   => ! empty($vd['sku']) ? $vd['sku'] : null,
                                'price' => (float) ($vd['price'] ?: 0),
                                'cost'  => (float) ($product->cost ?: 0),
                            ]);
                            $variantId = $variant->id;
                        } else {
                            // إنشاء فاريانت جديد إذا تمت إضافته من الفرونت
                            $variant = ProductVariant::create([
                                'product_id' => $product->id,
                                'name'       => $vd['name'] ?? 'Variant',
                                'sku'        => $vd['sku'] ?: null,
                                'price'      => (float) ($vd['price'] ?: 0),
                                'cost'       => (float) ($product->cost ?: 0),
                                'attributes' => $vd['attributes'] ?? [],
                            ]);
                            $variantId = $variant->id;
                        }

                        // تحديث الـ Stock الخاص بهاد الفاريانت
                        Stock::updateOrCreate(
                            ['product_id' => $product->id, 'variant_id' => $variantId, 'warehouse_id' => $warehouse->id],
                            ['quantity' => (int) ($vd['qty'] ?? 0), 'reorder_level' => 10]
                        );
                    }
                }
            });

            return redirect()->route('dashboard.products.index')->with('success', 'Product updated successfully.');

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
     * Publish/re-publish a product to its store's active platform connections.
     * Ported from the legacy ProductEditWizard::confirmPush() — same
     * ProductPushService calls, no new channel-listing logic.
     */
    public function push(Request $request, Product $product, ProductPushService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        if ($product->isVariable() && ! $product->variants()->exists()) {
            return back()->with('error', 'Add at least one variant before publishing a variable product.');
        }

        $connections = $store->connections()->where('status', 'active')->get();

        if ($connections->isEmpty()) {
            return back()->with('error', 'No active platform connections for this store.');
        }

        $results = [];

        foreach ($connections->groupBy('platform') as $platform => $platformConnections) {
            $platformResults = $product->external_id
                ? $service->pushProduct($product, $platform)
                : $service->createProduct($product, $platform);

            foreach ($platformResults as $r) {
                $results[] = array_merge($r, ['platform' => $platform]);
            }

            $product->refresh();
        }

        $succeeded = collect($results)->where('success', true)->count();
        $total     = count($results);

        if ($succeeded > 0) {
            return back()->with('success', $succeeded === $total
                ? "Published on {$total} platform(s)."
                : "Published on {$succeeded}/{$total} platform(s).");
        }

        return back()->with('error', collect($results)->pluck('message')->filter()->implode('; ') ?: 'Publish failed.');
    }
}